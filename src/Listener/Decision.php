<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\Foundation\ValidationException;
use ForumFortress\Flarum\Api\UnavailableException;

final class Decision
{
    public static function assertAllowed(?array $response): void
    {
        if (! $response) {
            return;
        }

        $decision = strtolower((string) ($response['decision'] ?? 'allow'));
        if ($decision === 'block') {
            throw new ValidationException([
                'forumfortress' => 'Forum Fortress rejected this request as suspected spam.',
            ]);
        }
    }

    public static function unavailable(UnavailableException $error): ValidationException
    {
        return new ValidationException([
            'forumfortress' => 'Forum Fortress is temporarily unavailable. Please try again.',
        ]);
    }
}
