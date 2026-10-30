<?php

namespace Ernestdefoe\Rotation\Console;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Charts;
use Ernestdefoe\Rotation\Rollups;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/** Rebuild the song and artist charts for today and yesterday. Scheduled hourly. */
class ChartsCommand extends AbstractCommand
{
    public function __construct(private Rollups $rollups, private Charts $charts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('rotation:charts')
            ->setDescription('Rebuild the Rotation song and artist charts.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days back to rebuild.', '2');
    }

    protected function fire(): int
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $count = max(1, min(3650, (int) $this->input->getOption('days')));
        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $days[] = $today->subDays($i)->toDateString();
        }

        $this->rollups->rebuildCharts($days);
        $this->charts->forget();
        $this->info('Rebuilt charts for '.count($days).' days.');

        return 0;
    }
}
