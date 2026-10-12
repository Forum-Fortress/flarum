<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\User\Event\Saving;
use ForumFortress\Flarum\Api\ForumFortressClient;
use ForumFortress\Flarum\Api\UnavailableException;
use ForumFortress\Flarum\Moderation\RepeatOffender;

final class CheckProfile
{
    public function __construct(private ForumFortressClient $client, private RepeatOffender $enforcement)
    {
    }

    public function handle(Saving $event): void
    {
        if (! $event->user->exists || $event->actor->isAdmin()) return;
        $attributes = (array) ($event->data['attributes'] ?? []);
        $profile = array_intersect_key($attributes, array_flip(['username', 'displayName', 'nickname', 'bio', 'signature']));
        if ($profile === []) return;
        $content = trim(implode("\n", array_map('strval', $profile)));
        try {
            $response = $this->client->check('profile', $this->client->userPayload($event->user, [
                'content' => $content,
                'links' => ForumFortressClient::extractExternalLinks($content, $this->client->domain()),
            ]));
            $this->enforcement->record((int) $event->user->id, (int) $event->actor->id, $response, $content);
            Decision::assertAllowed($response);
        } catch (UnavailableException $error) {
            throw Decision::unavailable($error);
        }
    }
}
