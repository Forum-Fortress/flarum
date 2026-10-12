<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\Settings\Event\Deserializing;
use Flarum\Settings\Event\Saving;
use ForumFortress\Flarum\Moderation\RepeatOffender;

final class RepeatOffenderAvailability
{
    public function __construct(private RepeatOffender $enforcement) {}

    public function handle(Deserializing|Saving $event): void
    {
        if ($event instanceof Deserializing) {
            $event->settings['forumfortress.repeat_offender_available'] = $this->enforcement->available() ? '1' : '0';
        } else {
            unset($event->settings['forumfortress.repeat_offender_available']);
        }
    }
}
