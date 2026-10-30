<?php

namespace Ernestdefoe\Rotation;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Raw plays → the rollups everything else reads.
 *
 * Days are UTC calendar days. A day is only ever rebuilt while its raw plays
 * are still kept; after that its rollups are final, which is how totals,
 * levels and all-time charts outlive the retention period.
 */
class Rollups
{
    /** One member's plays of one song in one day count at most this often in the charts. */
    public const CHART_REPEATS = 10;

    public function __construct(private ConnectionInterface $db, private Settings $settings, private Levels $levels)
    {
    }

    /** The first day whose raw plays are all still kept. */
    public function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay()->subDays($this->settings->retentionDays());
    }

    /**
     * Rebuild one member's days, then their totals and level.
     *
     * Listening time is each play's track length — Last.fm's, MusicBrainz's,
     * or the admin's average when neither knows — capped per day.
     *
     * @param list<string> $days Y-m-d
     */
    public function refreshUser(int $userId, array $days): void
    {
        $cutoff = $this->cutoff()->toDateString();
        $days = array_values(array_unique(array_filter($days, fn ($d) => $d >= $cutoff)));

        if ($days) {
            sort($days);
            $average = $this->settings->averageMs();
            $cap = $this->settings->dailyCapMs();

            $rows = $this->db->table('rotation_plays as p')
                ->join('rotation_tracks as t', 't.id', '=', 'p.track_id')
                ->where('p.user_id', $userId)
                ->where('p.played_at', '>=', $days[0].' 00:00:00')
                ->where('p.played_at', '<', CarbonImmutable::parse($days[count($days) - 1], 'UTC')->addDay()->toDateTimeString())
                ->get(['p.played_at', 't.duration_ms']);

            $totals = array_fill_keys($days, ['plays' => 0, 'ms' => 0]);
            foreach ($rows as $row) {
                $day = substr((string) $row->played_at, 0, 10);
                if (isset($totals[$day])) {
                    $totals[$day]['plays']++;
                    $totals[$day]['ms'] += $row->duration_ms ? (int) $row->duration_ms : $average;
                }
            }

            $upsert = [];
            $empty = [];
            foreach ($totals as $day => $t) {
                if ($t['plays'] > 0) {
                    $upsert[] = ['user_id' => $userId, 'day' => $day, 'plays' => $t['plays'], 'ms' => min($t['ms'], $cap)];
                } else {
                    $empty[] = $day;
                }
            }

            if ($upsert) {
                $this->db->table('rotation_days')->upsert($upsert, ['user_id', 'day'], ['plays', 'ms']);
            }
            if ($empty) {
                $this->db->table('rotation_days')->where('user_id', $userId)->whereIn('day', $empty)->delete();
            }
        }

        $this->refreshTotals($userId);
    }

    public function refreshTotals(int $userId): void
    {
        $sum = $this->db->table('rotation_days')->where('user_id', $userId)
            ->selectRaw('COALESCE(SUM(ms), 0) as ms, COALESCE(SUM(plays), 0) as plays')->first();

        $visibility = (string) $this->db->table('rotation_profiles')->where('user_id', $userId)->value('visibility');

        $this->db->table('rotation_profiles')->where('user_id', $userId)
            ->update(['total_ms' => (int) $sum->ms, 'total_plays' => (int) $sum->plays]);

        $this->levels->apply($userId, (int) $sum->ms, $visibility);
    }

    /** The kept days on which this member played anything. @return list<string> */
    public function daysOf(int $userId): array
    {
        return $this->db->table('rotation_plays')->where('user_id', $userId)
            ->where('played_at', '>=', $this->cutoff()->toDateTimeString())
            ->pluck('played_at')
            ->map(fn ($at) => substr((string) $at, 0, 10))
            ->unique()->values()->all();
    }

    /**
     * Rebuild the song and artist charts for these days from the plays of
     * members on the leaderboards. A song one member plays on repeat counts
     * a limited number of times a day, so nobody can top a chart alone.
     *
     * @param list<string> $days Y-m-d
     */
    public function rebuildCharts(array $days): void
    {
        $cutoff = $this->cutoff()->toDateString();

        foreach (array_unique($days) as $day) {
            if ($day < $cutoff) {
                continue;
            }

            $start = CarbonImmutable::parse($day, 'UTC');
            $rows = $this->db->table('rotation_plays as p')
                ->join('rotation_profiles as pr', 'pr.user_id', '=', 'p.user_id')
                ->where('pr.leaderboards', true)
                ->where('p.played_at', '>=', $start->toDateTimeString())
                ->where('p.played_at', '<', $start->addDay()->toDateTimeString())
                ->groupBy('p.user_id', 'p.track_id', 'p.artist_id')
                // 🚨 Aliased columns through select(), never raw: raw SQL skips the table prefix.
                ->select(['p.user_id', 'p.track_id', 'p.artist_id'])
                ->selectRaw('COUNT(*) as n')
                ->get();

            $tracks = [];
            $artistPlays = [];
            $artistUsers = [];
            foreach ($rows as $row) {
                $n = min((int) $row->n, self::CHART_REPEATS);
                $track = (int) $row->track_id;
                $artist = (int) $row->artist_id;
                $tracks[$track] = [
                    'plays' => ($tracks[$track]['plays'] ?? 0) + $n,
                    'listeners' => ($tracks[$track]['listeners'] ?? 0) + 1,
                ];
                $artistPlays[$artist] = ($artistPlays[$artist] ?? 0) + $n;
                $artistUsers[$artist][(int) $row->user_id] = true;
            }

            $insert = [];
            foreach ($tracks as $id => $t) {
                $insert[] = ['day' => $day, 'kind' => 't', 'entity_id' => $id, 'plays' => $t['plays'], 'listeners' => $t['listeners']];
            }
            foreach ($artistPlays as $id => $plays) {
                $insert[] = ['day' => $day, 'kind' => 'a', 'entity_id' => $id, 'plays' => $plays, 'listeners' => count($artistUsers[$id])];
            }

            $this->db->transaction(function () use ($day, $insert) {
                $this->db->table('rotation_chart')->where('day', $day)->delete();
                foreach (array_chunk($insert, 500) as $chunk) {
                    $this->db->table('rotation_chart')->insert($chunk);
                }
            });
        }
    }
}
