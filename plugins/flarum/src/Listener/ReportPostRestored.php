<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\Post\Event\Restored;
use Flarum\Settings\SettingsRepositoryInterface;
use ForumFortress\Flarum\Api\ForumFortressClient;

final class ReportPostRestored
{
    public function __construct(
        private ForumFortressClient $client,
        private SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(Restored $event): void
    {
        if ($this->settings->get('forumfortress.send_ham', '1') !== '1') {
            return;
        }

        $post = $event->post;
        $user = $post->user;
        $content = (string) $post->content;

        $this->client->report('ham', [
            'username' => (string) ($user?->username ?? ''),
            'email_domain' => strtolower((string) substr(strrchr((string) ($user?->email ?? ''), '@') ?: '', 1)),
            'links' => ForumFortressClient::extractExternalLinks($content, $this->client->domain()),
            'content_hash' => hash('sha256', $content),
            'payload' => [
                'action' => 'restore',
                'content' => $content,
                'remote_content_type' => 'post',
                'remote_content_id' => (string) $post->id,
                'remote_user_id' => $user ? (string) $user->id : null,
            ],
        ]);
    }
}
