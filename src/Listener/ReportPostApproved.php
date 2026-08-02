<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\Approval\Event\PostWasApproved;
use ForumFortress\Flarum\Api\ForumFortressClient;

final class ReportPostApproved
{
    public function __construct(private ForumFortressClient $client)
    {
    }

    public function handle(PostWasApproved $event): void
    {
        $post = $event->post;
        $content = (string) $post->content;
        $this->client->report('ham', [
            'username' => (string) ($post->user?->username ?? ''),
            'links' => ForumFortressClient::extractExternalLinks($content, $this->client->domain()),
            'content_hash' => hash('sha256', $content),
            'payload' => ['action' => 'approve', 'content' => $content, 'remote_content_id' => (string) $post->id],
        ]);
    }
}
