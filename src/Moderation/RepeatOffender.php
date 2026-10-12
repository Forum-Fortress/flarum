<?php

namespace ForumFortress\Flarum\Moderation;

use Carbon\Carbon;
use Flarum\Api\Client;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/** Durable local work; never suspend in a content validation listener. */
final class RepeatOffender
{
    public const WINDOW = 86400;
    public const DURATION = 604800;
    public const STATE = 'forumfortress_repeat_state';
    public const EVENTS = 'forumfortress_repeat_events';
    private array $staged = [];

    public function __construct(
        private ConnectionInterface $db,
        private ExtensionManager $extensions,
        private Container $container,
        private LoggerInterface $logger
    ) {
    }

    public function available(): bool
    {
        return $this->extensions->isEnabled('flarum-suspend')
            && (class_exists('Flarum\\Suspend\\Listener\\SaveSuspensionToDatabase')
                || class_exists('Flarum\\Suspend\\Listener\\SavingUser'))
            && $this->db->getSchemaBuilder()->hasColumn('users', 'suspended_until');
    }

    public static function reference(?array $response): ?string
    {
        // This advisory certifies the ORIGINAL enforced score strictly >80.
        // Never infer it from a review score, policy-only BLOCK or old response.
        if (($response['escalation_eligible'] ?? null) !== true
            || strtolower(trim((string) ($response['decision'] ?? ''))) !== 'block') {
            return null;
        }
        $reference = $response['decision_reference'] ?? null;
        if (is_string($reference) && trim($reference) !== '' && strlen($reference) <= 256) {
            return hash('sha256', 'reference:'.trim($reference));
        }
        $id = $response['decision_id'] ?? null;
        if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
            return hash('sha256', 'decision:'.(int) $id);
        }
        return null;
    }

    public function record(int $userId, int $actorId, ?array $response, string $body, ?CommentPost $post = null): void
    {
        $reference = self::reference($response);
        if ($userId <= 1 || $actorId !== $userId || ! $reference || trim($body) === '') return;
        // Existing edits and new posts must be owned by the authenticated actor.
        if ($post && (int) $post->user_id !== $userId) return;
        // Keep only immutable evidence until the outer native validation/save
        // transaction has unwound. Flarum 2 topics roll back rejected posts.
        $this->staged[] = [$userId, $reference, $body, $post && $post->exists ? (int) $post->id : 0];
    }

    public function flush(): void
    {
        if ($this->db->transactionLevel() !== 0) return;
        $staged = $this->staged;
        $this->staged = [];
        foreach ($staged as [$userId, $reference, $body, $postId]) $this->persist($userId, $reference, $body, $postId);
    }

    private function persist(int $userId, string $reference, string $body, int $postId): void
    {
        try {
            if (! $this->enabled() || ! $this->available()) return;
            $this->db->transaction(function () use ($userId, $reference, $body, $postId): void {
                $user = $this->lockedUser($userId);
                if (! $user || ! $this->eligible($user) || ! $this->enabled(true)) return;
                if ($postId > 0) {
                    $owner = $this->db->table('posts')->where('id', $postId)->lockForUpdate()->value('user_id');
                    if ((int) $owner !== $userId) return;
                }
                $now = time();
                // Locking the member serializes insertion, deduplication and work
                // publication across workers; the pending bit IS the local job.
                $this->db->table(self::STATE)->insertOrIgnore([
                    'user_id' => $userId, 'last_seen_at' => $now,
                ]);
                $state = $this->db->table(self::STATE)->where('user_id', $userId)->lockForUpdate()->first();
                if (! $state || $state->acted_at) return;
                $events = $this->db->table(self::EVENTS)->where('user_id', $userId);
                (clone $events)->where('observed_at', '<=', $now - self::WINDOW)->update(['consumed' => 1]);
                if ((clone $events)->count() >= 128) return;
                if ((clone $events)->where('consumed', 0)->count() < 2) {
                    (clone $events)->insertOrIgnore([
                        'user_id' => $userId, 'body_hash' => hash('sha256', $body),
                        'reference_hash' => $reference, 'observed_at' => $now,
                    ]);
                }
                $this->db->table(self::STATE)->where('user_id', $userId)->update([
                    'pending' => (clone $events)->where('consumed', 0)->count() >= 2 ? 1 : 0,
                    'last_seen_at' => $now,
                ]);
            });
        } catch (\Throwable $error) {
            // Optional automation cannot alter ordinary ALLOW/BLOCK/fail-open.
            $this->logger->warning('Forum Fortress repeat-offender evidence could not be saved.');
        }
    }

    public function run(): int
    {
        // Refuse inline/outer-transaction execution. Only the separate console
        // command processes work, irrespective of the site's queue driver.
        if ($this->db->transactionLevel() !== 0) throw new \LogicException('Repeat enforcement requires a separate request.');
        $ids = $this->db->table(self::STATE)->where('pending', 1)->orderBy('user_id')->limit(50)->pluck('user_id');
        $applied = 0;
        foreach ($ids as $id) {
            try {
                $applied += $this->apply((int) $id) ? 1 : 0;
            } catch (\Throwable $error) {
                // Retain pending work after failures; state and native mutation
                // roll back together, allowing the next scheduler run to retry.
                $this->logger->warning('Forum Fortress repeat-offender action failed for member {id}; retained for retry.', ['id' => (int) $id]);
            }
        }
        $this->prune();
        return $applied;
    }

    private function apply(int $id): bool
    {
        return $this->db->transaction(function () use ($id): bool {
            $user = $this->lockedUser($id);
            $state = $this->db->table(self::STATE)->where('user_id', $id)->lockForUpdate()->first();
            if (! $state || ! $state->pending || $state->acted_at) return false;
            $events = $this->db->table(self::EVENTS)->where('user_id', $id);
            $count = (clone $events)->where('consumed', 0)->where('observed_at', '>', time() - self::WINDOW)->count();
            if (! $this->enabled(true) || ! $this->available() || ! $user || ! $this->eligible($user) || $count < 2) {
                (clone $events)->update(['consumed' => 1]);
                $this->db->table(self::STATE)->where('user_id', $id)->update(['pending' => 0]);
                return false;
            }
            $actorId = User::query()->whereHas('groups', fn ($q) => $q->where('groups.id', Group::ADMINISTRATOR_ID))->orderBy('users.id')->value('users.id');
            $actor = $actorId ? $this->lockedUser((int) $actorId) : null;
            if (! $actor || ! $actor->isAdmin() || ! $actor->can('suspend', $user)) throw new \RuntimeException('No native suspension authority.');
            $until = Carbon::now()->addSeconds(self::DURATION);
            // Flarum 1.8 and 2.x share this in-process native endpoint. It performs
            // permission checks, validation, Suspend events and native persistence.
            // This is NOT a network call and runs only in this separate command.
            $result = $this->container->make(Client::class)->withActor($actor)->withBody(['data' => [
                'type' => 'users', 'id' => (string) $id, 'attributes' => [
                    'suspendedUntil' => $until->toIso8601String(),
                    'suspendReason' => 'Forum Fortress: two confirmed spam blocks within 24 hours.',
                ],
            ]])->patch('/users/'.$id);
            $fresh = User::query()->find($id);
            if ($result->getStatusCode() !== 200 || ! $fresh?->suspended_until
                || abs($fresh->suspended_until->getTimestamp() - $until->getTimestamp()) > 1) {
                throw new \RuntimeException('Native suspension was not confirmed.');
            }
            $this->db->table(self::STATE)->where('user_id', $id)->update(['pending' => 0, 'acted_at' => time()]);
            (clone $events)->update(['consumed' => 1]);
            return true;
        });
    }

    private function enabled(bool $lock = false): bool
    {
        $query = $this->db->table('settings')->whereIn('key', [
            'forumfortress.enabled', 'forumfortress.repeat_offender_suspend', 'extensions_enabled',
        ]);
        if ($lock) $query->lockForUpdate();
        $values = $query->pluck('value', 'key');
        $extensions = json_decode((string) ($values['extensions_enabled'] ?? '[]'), true);
        return ($values['forumfortress.enabled'] ?? '1') === '1'
            && ($values['forumfortress.repeat_offender_suspend'] ?? '0') === '1'
            && is_array($extensions) && in_array('forumfortress-flarum', $extensions, true)
            && in_array('flarum-suspend', $extensions, true);
    }

    private function lockedUser(int $id): ?User
    {
        $user = User::query()->whereKey($id)->lockForUpdate()->first();
        if (! $user) return null;
        // Also lock native group membership/permission rows. Fresh models avoid
        // cached permissions from the rejected request or a long-lived worker.
        $this->db->table('group_user')->where('user_id', $id)->lockForUpdate()->get();
        $this->db->table('group_permission')->lockForUpdate()->get();
        return $user;
    }

    private function eligible(User $user): bool
    {
        $now = time();
        if ((int) $user->id <= 1 || $user->isAdmin() || ! $user->is_email_confirmed
            || ! $user->joined_at || $user->joined_at->getTimestamp() <= $now - self::DURATION
            || $user->joined_at->getTimestamp() > $now || (int) $user->comment_count > 10
            || $user->suspended_until !== null) return false;
        // Conservatively exempt every custom/staff group, including moderators.
        foreach ($user->groups as $group) if ((int) $group->id !== Group::MEMBER_ID) return false;
        // Protect members granted moderator powers via the default member/guest
        // group, and scoped/custom moderation permissions. Unknown powers fail safe.
        $ordinary = ['viewForum', 'startDiscussion', 'discussion.start', 'discussion.reply', 'discussion.likePosts',
            'discussion.flagPosts', 'user.editAvatar', 'user.viewLastSeenAt', 'searchUsers'];
        // Flarum 2 caches permissions by group set across User instances. Query
        // the native permission relation directly under the locked rows instead.
        foreach ($user->permissions()->pluck('permission') as $permission) if (! in_array($permission, $ordinary, true)) return false;
        return true;
    }

    private function prune(): void
    {
        // Seven-day receipts cover the entire eligibility lifetime. Keep the
        // acted_at tombstone while the member exists, preserving native reversal.
        $ids = $this->db->table(self::EVENTS)->where('observed_at', '<=', time() - self::DURATION)->limit(500)->pluck('id');
        if ($ids->isNotEmpty()) $this->db->table(self::EVENTS)->whereIn('id', $ids)->delete();
        $ids = $this->db->table(self::STATE)->where('pending', 0)->where('acted_at', 0)
            ->where('last_seen_at', '<=', time() - self::DURATION)->limit(500)->pluck('user_id');
        if ($ids->isNotEmpty()) $this->db->table(self::STATE)->whereIn('user_id', $ids)->delete();
        $ids = $this->db->table(self::STATE)->whereNotIn('user_id', $this->db->table('users')->select('id'))
            ->limit(500)->pluck('user_id');
        if ($ids->isNotEmpty()) $this->db->table(self::STATE)->whereIn('user_id', $ids)->delete();
    }
}
