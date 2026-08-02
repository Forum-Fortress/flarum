<?php

namespace ForumFortress\Flarum\Moderation;

use Carbon\Carbon;
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Flags\Flag;
use Flarum\Foundation\Config;
use Flarum\Group\Group;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use ForumFortress\Flarum\Api\ForumFortressClient;
use Illuminate\Contracts\Events\Dispatcher;

final class Bridge
{
    public function __construct(
        private ForumFortressClient $client,
        private Config $config,
        private Dispatcher $events
    ) {
    }

    public function sync(): array
    {
        $items = $this->collectQueueItems();
        $push = $this->client->moderationQueueSync($items);
        $pulled = $this->client->pullModerationActions();
        $results = $this->executeActions((array) ($pulled['actions'] ?? []));
        $ack = $results === [] ? [] : $this->client->acknowledgeModerationActions($results);

        return ['queue_items' => count($items), 'push' => $push, 'actions' => count($results), 'ack' => $ack];
    }

    public function collectQueueItems(): array
    {
        $posts = CommentPost::query()
            ->where('is_approved', false)
            ->with(['discussion', 'user'])
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $items = [];
        foreach ($posts as $post) {
            $isThread = (int) $post->number === 1;
            $discussion = $post->discussion;
            $user = $post->user;
            $content = (string) $post->content;
            $items[] = [
                'remote_content_type' => $isThread ? 'thread' : 'post',
                'remote_content_id' => (string) ($isThread ? $post->discussion_id : $post->id),
                'title' => $isThread ? (string) ($discussion?->title ?? '') : 'Reply',
                'excerpt' => mb_substr($content, 0, 280),
                'username' => (string) ($user?->username ?? ''),
                'remote_user_id' => $user ? (string) $user->id : null,
                'content_date' => $post->created_at?->getTimestamp(),
                'content_url' => rtrim((string) $this->config['url'], '/').'/d/'.$post->discussion_id.'/'.$post->number,
                'available_actions' => ['approve', 'reject', 'spam_clean'],
                'payload' => ['content_type' => $isThread ? 'thread' : 'post', 'post_id' => (int) $post->id],
            ];
        }
        return $items;
    }

    public function executeActions(array $actions): array
    {
        $actor = $this->systemModerator();
        $results = [];
        foreach ($actions as $action) {
            $id = (int) ($action['id'] ?? 0);
            try {
                if (! $actor) throw new \RuntimeException('No administrator is available to apply moderation actions.');
                $post = $this->findPost((string) ($action['remote_content_type'] ?? ''), (int) ($action['remote_content_id'] ?? 0));
                if (! $post) {
                    $results[] = ['id' => $id, 'status' => 'applied', 'message' => 'Queue item is no longer pending.'];
                    continue;
                }
                match ((string) ($action['action'] ?? 'approve')) {
                    'reject' => $this->reject($post),
                    'spam_clean' => $this->spamClean($post),
                    default => $this->approve($post, $actor),
                };
                $results[] = ['id' => $id, 'status' => 'applied', 'message' => 'Action applied.'];
            } catch (\Throwable $error) {
                $results[] = ['id' => $id, 'status' => 'failed', 'message' => $error->getMessage()];
            }
        }
        return $results;
    }

    private function findPost(string $type, int $id): ?CommentPost
    {
        if ($id < 1) return null;
        $query = CommentPost::query()->where('is_approved', false);
        return $type === 'thread'
            ? $query->where('discussion_id', $id)->where('number', 1)->first()
            : $query->whereKey($id)->first();
    }

    private function approve(CommentPost $post, User $actor): void
    {
        $post->is_approved = true;
        $post->save();
        if ((int) $post->number === 1 && $post->discussion) {
            $post->discussion->is_approved = true;
            $post->discussion->save();
        }
        Flag::query()->where('post_id', $post->id)->where('type', 'approval')->delete();
        $this->events->dispatch(new PostWasApproved($post, $actor));
    }

    private function reject(CommentPost $post): void
    {
        Flag::query()->where('post_id', $post->id)->where('type', 'approval')->delete();
        if ((int) $post->number === 1 && $post->discussion) {
            $post->discussion->delete();
        } else {
            $post->delete();
        }
    }

    private function spamClean(CommentPost $post): void
    {
        $user = $post->user;
        if ($user && ! $user->isAdmin() && $user->getConnection()->getSchemaBuilder()->hasColumn('users', 'suspended_until')) {
            $user->suspended_until = Carbon::now()->addYears(100);
            $user->save();
        }
        $this->reject($post);
    }

    private function systemModerator(): ?User
    {
        return User::query()->whereHas('groups', fn ($query) => $query->where('groups.id', Group::ADMINISTRATOR_ID))->orderBy('users.id')->first();
    }
}
