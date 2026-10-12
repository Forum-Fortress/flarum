<?php

namespace ForumFortress\Flarum\Console;

use Flarum\Console\AbstractCommand;
use ForumFortress\Flarum\Moderation\RepeatOffender;

final class RepeatOffenderCommand extends AbstractCommand
{
    public function __construct(private RepeatOffender $enforcement) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('forumfortress:repeat-offenders')->setDescription('Process local opt-in seven-day spam suspensions.');
    }

    protected function fire(): int
    {
        $this->info(sprintf('Applied %d native suspensions.', $this->enforcement->run()));
        return 0;
    }
}
