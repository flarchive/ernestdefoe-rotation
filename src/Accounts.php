<?php

namespace Ernestdefoe\Rotation;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Job\RebuildCharts;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;

/**
 * Connecting, disconnecting and a member's own choices.
 *
 * 🚨 Disconnecting takes everything personal with it at once: the account,
 * every raw play, the daily totals, the snapshot and the level. What remains
 * are chart counts from days older than the retention period, which hold
 * numbers per song, not people.
 */
class Accounts
{
    public function __construct(
        private ConnectionInterface $db,
        private Settings $settings,
        private Rollups $rollups,
        private Charts $charts,
        private Queue $queue,
    ) {
    }

    /** Link a verified account. False when another member already linked it. */
    public function connect(int $userId, string $provider, string $name): bool
    {
        $owner = $this->db->table('rotation_accounts')->where('provider', $provider)->where('external_name', $name)->value('user_id');
        if ($owner !== null && (int) $owner !== $userId) {
            return false;
        }

        $now = CarbonImmutable::now('UTC')->toDateTimeString();
        $existing = $this->db->table('rotation_accounts')->where('user_id', $userId)->where('provider', $provider)->first(['id', 'external_name']);

        if ($existing && $existing->external_name !== $name) {
            // A different account replaces the old one: its plays go with it.
            $this->purge($userId, false);
            $existing = null;
        }

        if ($existing) {
            $this->db->table('rotation_accounts')->where('id', $existing->id)
                ->update(['status' => 'ok', 'fail_count' => 0, 'last_error' => null, 'next_poll_at' => $now]);
        } else {
            $this->db->table('rotation_accounts')->insert([
                'user_id' => $userId, 'provider' => $provider, 'external_name' => $name,
                'status' => 'ok', 'next_poll_at' => $now, 'created_at' => $now,
            ]);
        }

        $this->db->table('rotation_profiles')->insertOrIgnore(['user_id' => $userId, 'visibility' => $this->settings->defaultVisibility()]);

        return true;
    }

    public function disconnect(int $userId): void
    {
        $this->purge($userId, true);
    }

    /** The member's choices; only the ones given change. */
    public function update(int $userId, array $prefs): void
    {
        $before = $this->db->table('rotation_profiles')->where('user_id', $userId)->first();
        if (! $before) {
            return;
        }

        $changes = [];
        if (isset($prefs['visibility']) && in_array($prefs['visibility'], ['everyone', 'members', 'me'], true)) {
            $changes['visibility'] = $prefs['visibility'];
        }
        if (isset($prefs['showNowPlaying'])) {
            $changes['show_now_playing'] = (bool) $prefs['showNowPlaying'];
        }
        if (isset($prefs['leaderboards'])) {
            $changes['leaderboards'] = (bool) $prefs['leaderboards'];
        }
        if (! $changes) {
            return;
        }

        $this->db->table('rotation_profiles')->where('user_id', $userId)->update($changes);

        if (isset($changes['visibility']) && $changes['visibility'] !== $before->visibility) {
            $this->db->table('users')->where('id', $userId)
                ->update(['rotation_level' => $changes['visibility'] === 'everyone' ? $before->level : null]);
        }

        // Joining or leaving the charts changes the days they still have plays for.
        if (isset($changes['leaderboards']) && $changes['leaderboards'] !== (bool) $before->leaderboards) {
            $days = $this->rollups->daysOf($userId);
            if ($days) {
                $this->queue->push(new RebuildCharts($days));
            }
        }

        $this->charts->forget();
    }

    /** Remove a member's account and listening data. */
    public function purge(int $userId, bool $profile): void
    {
        $charted = (bool) $this->db->table('rotation_profiles')->where('user_id', $userId)->value('leaderboards');
        $days = $charted ? $this->rollups->daysOf($userId) : [];

        $this->db->transaction(function () use ($userId, $profile) {
            $this->db->table('rotation_accounts')->where('user_id', $userId)->delete();
            $this->db->table('rotation_plays')->where('user_id', $userId)->delete();
            $this->db->table('rotation_days')->where('user_id', $userId)->delete();
            if ($profile) {
                $this->db->table('rotation_profiles')->where('user_id', $userId)->delete();
            } else {
                $this->db->table('rotation_profiles')->where('user_id', $userId)->update([
                    'now_playing' => null, 'now_playing_at' => null, 'recent' => null, 'tops' => null,
                    'total_ms' => 0, 'total_plays' => 0, 'level' => null,
                ]);
            }
            $this->db->table('users')->where('id', $userId)->update(['rotation_level' => null]);
        });

        if ($days) {
            $this->queue->push(new RebuildCharts($days));
        }
        $this->charts->forget();
    }
}
