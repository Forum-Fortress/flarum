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
        // Registration was already checked during Saving. Activation is a
        // successful lifecycle notification, not independent spam evidence.
        $this->client->report('ham', array_merge(
            $this->client->userPayload($event->user),
            [
                'payload' => [
                    'action' => 'activate',
                    'remote_user_id' => (string) $event->user->id,
                ],
            ],
        ));
    }
}
