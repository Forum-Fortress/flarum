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
            // Login checks are observational only. Forum Fortress may record
            // network or identity risk, but it must never revoke a valid login.
            $this->client->check('login', $payload);
            $this->client->report('login', $payload);
        } catch (UnavailableException $error) {
            // Authentication must continue even when the anti-spam service is
            // unavailable; the forum owns the credential decision.
            return;
        }
    }
}
