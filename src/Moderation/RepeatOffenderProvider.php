<?php

namespace ForumFortress\Flarum\Moderation;

use Flarum\Foundation\AbstractServiceProvider;

final class RepeatOffenderProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // The validation listeners and outer API middleware share staged evidence.
        $this->container->singleton(RepeatOffender::class);
    }
}
