<?php

namespace Ernestdefoe\Rotation;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Provider\Listening;
use Ernestdefoe\Rotation\Provider\ListeningProvider;
use Ernestdefoe\Rotation\Provider\ProviderException;
use Ernestdefoe\Rotation\Provider\Providers;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches members' plays in the background, never on a page view.
 *
 * Each run takes the accounts that are due, oldest first, until its time or
 * the request budget runs out, then spends what is left looking up track
 * lengths. How soon an account is due again follows how its member listens:
 * a few minutes while something is playing, hours once they have gone quiet.
 * Failures back off; an account the service says is hidden or gone is only
 * checked once a day, and comes back by itself once it is fixed.
 */
class Poller
{
    public function __construct(
        private ConnectionInterface $db,
        private Providers $providers,
        private Catalog $catalog,
        private Rollups $rollups,
        private Durations $durations,
        private Budget $budget,
        private LoggerInterface $log,
    ) {
    }

    /** @return array{accounts: int, plays: int, lengths: int} */
    public function run(float $deadline): array
    {
        $stats = ['accounts' => 0, 'plays' => 0, 'lengths' => 0];

        $due = $this->db->table('rotation_accounts')
            ->where('next_poll_at', '<=', CarbonImmutable::now('UTC')->toDateTimeString())
            ->orderBy('next_poll_at')
            ->limit(200)
            ->get();

        foreach ($due as $account) {
            $provider = $this->providers->get((string) $account->provider);
            if (! $provider || ! $provider->configured() || $this->budget->paused($provider->key())) {
                continue;
            }
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                $stats['plays'] += $this->poll($provider, $account);
                $stats['accounts']++;
            } catch (ProviderException $e) {
                if ($this->failed($provider, $account, $e)) {
                    break;
                }
            }
        }

        if (microtime(true) < $deadline) {
            $stats['lengths'] = $this->durations->resolve($deadline);
        }

        return $stats;
    }

    /** Poll one account now. Returns how many new plays were stored. */
    public function poll(ListeningProvider $provider, object $account): int
    {
        $since = $account->last_played_at ? CarbonImmutable::parse($account->last_played_at, 'UTC') : null;
        $listening = $provider->recent((string) $account->external_name, $since);

        [$stored, $newest] = $this->store((int) $account->user_id, $listening);

        // Tops change slowly: once a day. A failure here never costs the plays above.
        $tops = null;
        if (! $account->tops_at || CarbonImmutable::parse($account->tops_at, 'UTC')->lt(CarbonImmutable::now('UTC')->subDay())) {
            try {
                $tops = $provider->tops((string) $account->external_name);
            } catch (ProviderException $e) {
                if ($e->kind === ProviderException::RATE_LIMITED) {
                    $this->budget->pause($provider->key(), 300);
                }
            }
        }
        $now = CarbonImmutable::now('UTC');
        $last = $newest ?? $since;

        $this->db->table('rotation_accounts')->where('id', $account->id)->update([
            'status' => 'ok',
            'fail_count' => 0,
            'last_error' => null,
            'last_polled_at' => $now->toDateTimeString(),
            'last_played_at' => $last?->toDateTimeString(),
            'next_poll_at' => $now->addMinutes($this->interval($listening, $last, (int) $account->user_id, $account->created_at))->toDateTimeString(),
        ] + ($tops !== null ? ['tops_at' => $now->toDateTimeString()] : []));

        $this->snapshot((int) $account->user_id, $listening, $tops);

        return $stored;
    }

    /**
     * Store the plays that pass the fake-scrobble checks: nothing timestamped
     * in the future, nothing more than two weeks old. Returns how many were
     * new, and the newest play seen.
     *
     * @return array{0: int, 1: ?CarbonImmutable}
     */
    private function store(int $userId, Listening $listening): array
    {
        $now = CarbonImmutable::now('UTC');
        $oldest = $now->subDays(14);
        $plays = array_values(array_filter($listening->plays, fn ($p) => $p['playedAt']->lte($now->addMinutes(5)) && $p['playedAt']->gte($oldest)));

        $newest = null;
        foreach ($listening->plays as $play) {
            if ($play['playedAt']->lte($now->addMinutes(5)) && ($newest === null || $play['playedAt']->gt($newest))) {
                $newest = $play['playedAt'];
            }
        }

        if (! $plays) {
            return [0, $newest];
        }

        $ids = $this->catalog->ids($plays);
        $rows = [];
        $days = [];
        foreach ($plays as $play) {
            $id = $ids[Catalog::trackKey($play['artist'], $play['title'])];
            $at = $play['playedAt']->utc();
            $rows[] = ['user_id' => $userId, 'track_id' => $id['track'], 'artist_id' => $id['artist'], 'played_at' => $at->toDateTimeString()];
            $days[$at->toDateString()] = true;
        }

        $stored = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $stored += $this->db->table('rotation_plays')->insertOrIgnore($chunk);
        }

        if ($stored > 0) {
            $this->rollups->refreshUser($userId, array_keys($days));
        }

        return [$stored, $newest];
    }

    /** The profile's snapshot, written only when something in it changed. */
    private function snapshot(int $userId, Listening $listening, ?array $tops): void
    {
        $profile = $this->db->table('rotation_profiles')->where('user_id', $userId)->first(['now_playing', 'recent']);
        if (! $profile) {
            return;
        }

        $now = CarbonImmutable::now('UTC')->toDateTimeString();
        $nowPlaying = $listening->nowPlaying ? json_encode($listening->nowPlaying) : null;
        $recent = json_encode($listening->recent);
        $changes = [];

        if ($nowPlaying !== null || $profile->now_playing !== null) {
            $changes['now_playing'] = $nowPlaying;
            $changes['now_playing_at'] = $nowPlaying !== null ? $now : null;
        }
        if ($listening->recent && $recent !== $profile->recent) {
            $changes['recent'] = $recent;
        }
        if ($tops !== null) {
            $changes['tops'] = json_encode($tops);
        }

        if ($changes) {
            $this->db->table('rotation_profiles')->where('user_id', $userId)->update($changes + ['updated_at' => $now]);
        }
    }

    /** Minutes until this account is polled again. */
    private function interval(Listening $listening, ?CarbonImmutable $last, int $userId, ?string $connectedAt): int
    {
        if ($listening->more) {
            return 1;
        }
        if ($listening->nowPlaying) {
            return 3;
        }

        $now = CarbonImmutable::now('UTC');

        // Just connected: someone trying it out sees their next play soon.
        if ($connectedAt && CarbonImmutable::parse($connectedAt, 'UTC')->gt($now->subDay())) {
            return 15;
        }

        $seen = $this->db->table('users')->where('id', $userId)->value('last_seen_at');
        if (! $seen || CarbonImmutable::parse($seen, 'UTC')->lt($now->subDays(30))) {
            return 24 * 60;
        }

        return match (true) {
            $last !== null && $last->gt($now->subDay()) => 15,
            $last !== null && $last->gt($now->subDays(7)) => 60,
            default => 6 * 60,
        };
    }

    /** Record a failure. Returns true when the whole run should stop. */
    private function failed(ListeningProvider $provider, object $account, ProviderException $e): bool
    {
        $now = CarbonImmutable::now('UTC');

        switch ($e->kind) {
            case ProviderException::BUDGET:
                return true;

            case ProviderException::RATE_LIMITED:
                $this->budget->pause($provider->key(), 300);

                return true;

            case ProviderException::BAD_KEY:
                $this->budget->pause($provider->key(), 1800);
                $this->log->warning("[rotation] {$provider->key()} refused the forum's API key; polling paused for 30 minutes.");

                return true;

            case ProviderException::PRIVATE:
            case ProviderException::MISSING:
                $this->db->table('rotation_accounts')->where('id', $account->id)->update([
                    'status' => $e->kind,
                    'last_error' => $e->kind,
                    'last_polled_at' => $now->toDateTimeString(),
                    'next_poll_at' => $now->addDay()->toDateTimeString(),
                ]);
                $this->db->table('rotation_profiles')->where('user_id', $account->user_id)->update(['now_playing' => null, 'now_playing_at' => null]);

                return false;

            default:
                $fails = (int) $account->fail_count + 1;
                $this->db->table('rotation_accounts')->where('id', $account->id)->update([
                    'fail_count' => min($fails, 1000),
                    'last_error' => mb_substr($e->getMessage(), 0, 100),
                    'last_polled_at' => $now->toDateTimeString(),
                    'next_poll_at' => $now->addMinutes((int) min(2 ** min($fails, 12) * 5, 24 * 60))->toDateTimeString(),
                ]);

                return false;
        }
    }
}
