<?php

namespace Ernestdefoe\Rotation\Job;

use Ernestdefoe\Rotation\Charts;
use Ernestdefoe\Rotation\Rollups;
use Flarum\Queue\AbstractJob;

/** Rebuild the song and artist charts for some days, after a member joins or leaves them. */
class RebuildCharts extends AbstractJob
{
    /** @param list<string> $days Y-m-d */
    public function __construct(public array $days)
    {
        parent::__construct();
    }

    public function handle(Rollups $rollups, Charts $charts): void
    {
        $rollups->rebuildCharts($this->days);
        $charts->forget();
    }
}
