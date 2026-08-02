<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\Post\Event\Hidden;
use ForumFortress\Flarum\Api\ForumFortressClient;

final class ReportPostHidden
{
    public function __construct(private ForumFortressClient $client)
    {
    }

    public function handle(Hidden $event): void
    {
        $post = $event->post;
        $user = $post->user;
        $content = (string) $post->content;

        $this->client->report('moderation', [
            'username' => (string) ($user?->username ?? ''),
            'email_domain' => strtolower((string) substr(strrchr((string) ($user?->email ?? ''), '@') ?: '', 1)),
            'links' => ForumFortressClient::extractExternalLinks($content, $this->client->domain()),
            'content_hash' => hash('sha256', $content),
            'payload' => [
                'action' => 'hide',
                'content' => $content,
                'remote_content_type' => 'post',
                'remote_content_id' => (string) $post->id,
                'remote_user_id' => $user ? (string) $user->id : null,
            ],
        ]);
    }
}
