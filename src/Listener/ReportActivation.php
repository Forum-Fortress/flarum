<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\User\Event\Activated;
use ForumFortress\Flarum\Api\ForumFortressClient;

final class ReportActivation
{
    public function __construct(private ForumFortressClient $client)
    {
    }

    public function handle(Activated $event): void
    {
        $this->client->report('register', $this->client->userPayload($event->user, ['remote_user_id' => (string) $event->user->id]));
    }
}
