<?php

namespace Ernestdefoe\Rotation;

use Illuminate\Database\ConnectionInterface;

/**
 * Levels from listening time, by the admin's bands. Rotation works them out
 * itself: no levels extension is needed.
 *
 * Each member's level is stored twice: on their profile row (what their music
 * tab shows) and on the users table, which is what puts it beside their posts
 * without a query per author. The users copy is set only while the profile is
 * visible to everyone, so a hidden profile never shows a level anywhere.
 */
class Levels
{
    public function __construct(private Settings $settings, private ConnectionInterface $db)
    {
    }

    /** @return array{from: float, to: float|null, level: int, name: string}|null */
    public function bandFor(int $ms): ?array
    {
        $hours = $ms / 3600000;

        foreach ($this->settings->bands() as $band) {
            if ($hours >= $band['from'] && ($band['to'] === null || $hours < $band['to'])) {
                return $band;
            }
        }

        return null;
    }

    /** The next band up from this amount, for "N hours to level X". */
    public function nextBand(int $ms): ?array
    {
        $current = $this->bandFor($ms);
        $hours = $ms / 3600000;
        $next = null;

        foreach ($this->settings->bands() as $band) {
            if ($band['from'] > $hours && ($current === null || $band['level'] > $current['level']) && ($next === null || $band['from'] < $next['from'])) {
                $next = $band;
            }
        }

        return $next;
    }

    public function apply(int $userId, int $totalMs, string $visibility): ?int
    {
        $level = $this->bandFor($totalMs)['level'] ?? null;

        $this->db->table('rotation_profiles')->where('user_id', $userId)->update(['level' => $level]);
        $this->db->table('users')->where('id', $userId)->update(['rotation_level' => $visibility === 'everyone' ? $level : null]);

        return $level;
    }

    /** After the admin changes the bands: every member, one update per level. */
    public function relevelAll(): void
    {
        $byLevel = [];
        $public = [];

        $this->db->table('rotation_profiles')->orderBy('user_id')->select(['user_id', 'total_ms', 'visibility'])
            ->chunk(1000, function ($rows) use (&$byLevel, &$public) {
                foreach ($rows as $row) {
                    $key = (string) ($this->bandFor((int) $row->total_ms)['level'] ?? '');
                    $byLevel[$key][] = (int) $row->user_id;
                    if ($row->visibility === 'everyone') {
                        $public[$key][] = (int) $row->user_id;
                    }
                }
            });

        foreach ($byLevel as $key => $ids) {
            $level = $key === '' ? null : (int) $key;
            foreach (array_chunk($ids, 1000) as $chunk) {
                $this->db->table('rotation_profiles')->whereIn('user_id', $chunk)->update(['level' => $level]);
            }
            foreach (array_chunk($public[$key] ?? [], 1000) as $chunk) {
                $this->db->table('users')->whereIn('id', $chunk)->update(['rotation_level' => $level]);
            }
        }
    }
}
