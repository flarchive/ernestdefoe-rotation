<?php

namespace Ernestdefoe\Rotation\Console;

use Ernestdefoe\Rotation\Poller;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/** Fetch the plays of accounts that are due. Scheduled every minute, never overlapping itself. */
class PollCommand extends AbstractCommand
{
    public function __construct(private Poller $poller)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('rotation:poll')
            ->setDescription("Fetch members' new plays from Last.fm.")
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Stop starting new requests after this many seconds.', '40');
    }

    protected function fire(): int
    {
        $seconds = max(1, min(600, (int) $this->input->getOption('seconds')));
        $stats = $this->poller->run(microtime(true) + $seconds);

        $this->info("Polled {$stats['accounts']} accounts, stored {$stats['plays']} plays, settled {$stats['lengths']} track lengths.");

        return 0;
    }
}
