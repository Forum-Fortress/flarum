<?php

namespace ForumFortress\Flarum\Console;

use Flarum\Console\AbstractCommand;
use ForumFortress\Flarum\Api\ForumFortressClient;

final class SyncCommand extends AbstractCommand
{
    public function __construct(private ForumFortressClient $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('forumfortress:sync')
            ->setDescription('Bootstrap or ping the Forum Fortress service.');
    }

    protected function fire(): int
    {
        if (! $this->client->isEnabled()) {
            $this->info('Forum Fortress is disabled.');
            return 0;
        }

        if (($this->client->sync()['enabled'] ?? false) === true) {
            $this->info('Forum Fortress synchronization completed.');
            return 0;
        }

        $this->error('Forum Fortress synchronization was not completed.');
        return 1;
    }
}
