<?php

namespace ForumFortress\Flarum\Console;

use Flarum\Console\AbstractCommand;
use ForumFortress\Flarum\Moderation\Bridge;

final class ModerationSyncCommand extends AbstractCommand
{
    public function __construct(private Bridge $bridge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('forumfortress:moderation-sync')->setDescription('Synchronize Flarum Approval with Forum Fortress moderation.');
    }

    protected function fire(): int
    {
        $result = $this->bridge->sync();
        $this->info(sprintf('Synchronized %d queue items and processed %d actions.', $result['queue_items'], $result['actions']));
        return 0;
    }
}
