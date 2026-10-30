<?php

namespace Ernestdefoe\Rotation\Console;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Rollups;
use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;

/**
 * Remove raw plays older than the admin's retention period (whole days only,
 * so a kept day is never half there), and forget failed player look-ups after
 * a week so they are tried again. Scheduled daily.
 */
class PruneCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db, private Rollups $rollups)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('rotation:prune')->setDescription('Remove Rotation plays older than the retention period.');
    }

    protected function fire(): int
    {
        $cutoff = $this->rollups->cutoff()->toDateTimeString();
        $removed = 0;

        do {
            $ids = $this->db->table('rotation_plays')->where('played_at', '<', $cutoff)->limit(5000)->pluck('id')->all();
            if ($ids) {
                $removed += $this->db->table('rotation_plays')->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === 5000);

        $this->db->table('rotation_embeds')->whereNull('ref')
            ->where('resolved_at', '<', CarbonImmutable::now('UTC')->subWeek()->toDateTimeString())->delete();

        $this->info("Removed $removed plays.");

        return 0;
    }
}
