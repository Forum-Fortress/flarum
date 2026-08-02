<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\User\Event\LoggedIn;
use ForumFortress\Flarum\Api\ForumFortressClient;
use ForumFortress\Flarum\Api\UnavailableException;

final class CheckLogin
{
    public function __construct(private ForumFortressClient $client)
    {
    }

    public function handle(LoggedIn $event): void
    {
        if ($event->user->isAdmin()) {
            return;
        }

        try {
            $payload = $this->client->userPayload($event->user, [
                'ip' => (string) ($event->token->last_ip_address ?? ''),
                'user_agent' => (string) ($event->token->last_user_agent ?? ''),
            ]);
            $response = $this->client->check('login', $payload);
            $decision = strtolower((string) ($response['decision'] ?? 'allow'));

            if ($decision === 'block') {
                $event->token->delete();
            }

            Decision::assertAllowed($response);
            $this->client->report('login', $payload);
        } catch (UnavailableException $error) {
            throw Decision::unavailable($error);
        }
    }
}
