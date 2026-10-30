<?php

namespace Ernestdefoe\Rotation;

use Carbon\CarbonImmutable;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Leaderboards and widgets, read from the rollups only: a fixed handful of
 * queries whatever the number of members or plays, and cached.
 *
 * 🚨 Privacy: a member is on the listeners board, or counted in song and
 * artist charts, only once they chose to be on the leaderboards. The listeners
 * board and "now playing" also follow each member's profile visibility, and
 * their cache is kept per audience (guests, members), so a shared cache can
 * never show a guest a members-only profile.
 */
class Charts
{
    public const PERIODS = ['week' => 7, 'month' => 30, 'year' => 365, 'all' => null];
    /** "Now playing" means a play reported within this many minutes. */
    public const LIVE_MINUTES = 10;

    public function __construct(
        private ConnectionInterface $db,
        private Cache $cache,
        private SlugManager $slugs,
    ) {
    }

    public static function period(mixed $value): string
    {
        return is_string($value) && array_key_exists($value, self::PERIODS) ? $value : 'week';
    }

    /** Songs or artists by plays. @param 't'|'a' $kind */
    public function entities(string $kind, string $period, int $limit): array
    {
        return $this->cache->remember("rotation.chart.$kind.$period.$limit", 600, function () use ($kind, $period, $limit) {
            $query = $this->db->table('rotation_chart')->where('kind', $kind);
            if ($since = $this->since($period)) {
                $query->where('day', '>=', $since);
            }

            $rows = $query->groupBy('entity_id')
                ->selectRaw('entity_id, SUM(plays) as plays')
                ->orderByDesc('plays')->orderBy('entity_id')
                ->limit($limit)->get();

            $ids = $rows->pluck('entity_id')->map(fn ($id) => (int) $id)->all();
            $names = $kind === 't'
                ? $this->db->table('rotation_tracks as t')->join('rotation_artists as a', 'a.id', '=', 't.artist_id')
                    ->whereIn('t.id', $ids)->get(['t.id', 't.title', 'a.name as artist'])->keyBy('id')
                : $this->db->table('rotation_artists')->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');

            $out = [];
            foreach ($rows as $i => $row) {
                $n = $names[(int) $row->entity_id] ?? null;
                if (! $n) {
                    continue;
                }
                $out[] = $kind === 't'
                    ? ['rank' => $i + 1, 'title' => $n->title, 'artist' => $n->artist, 'plays' => (int) $row->plays, 'url' => self::lastfmUrl($n->artist, $n->title)]
                    : ['rank' => $i + 1, 'name' => $n->name, 'plays' => (int) $row->plays, 'url' => self::lastfmUrl($n->name)];
            }

            return $out;
        });
    }

    /** Members by listening time. */
    public function listeners(User $actor, string $period, int $limit): array
    {
        $scope = Access::scope($actor);
        $ranked = $this->cache->remember('rotation.listeners.'.implode('-', $scope).".$period.$limit", 600, function () use ($scope, $period, $limit) {
            if ($since = $this->since($period)) {
                $rows = $this->db->table('rotation_days as d')
                    ->join('rotation_profiles as p', 'p.user_id', '=', 'd.user_id')
                    ->where('p.leaderboards', true)->whereIn('p.visibility', $scope)
                    ->where('d.day', '>=', $since)
                    ->groupBy('d.user_id')
                    // Unqualified sums: only rotation_days has ms and plays, and raw SQL skips the table prefix.
                    ->select('d.user_id')->selectRaw('SUM(ms) as ms, SUM(plays) as plays')
                    ->orderByDesc('ms')->orderBy('d.user_id')
                    ->limit($limit)->get();
            } else {
                $rows = $this->db->table('rotation_profiles')
                    ->where('leaderboards', true)->whereIn('visibility', $scope)->where('total_ms', '>', 0)
                    ->orderByDesc('total_ms')->orderBy('user_id')
                    ->limit($limit)->get(['user_id', 'total_ms as ms', 'total_plays as plays']);
            }

            return $rows->map(fn ($r) => ['id' => (int) $r->user_id, 'ms' => (int) $r->ms, 'plays' => (int) $r->plays])->all();
        });

        $users = $this->users($actor, array_column($ranked, 'id'));
        $out = [];
        foreach ($ranked as $row) {
            if ($user = $users[$row['id']] ?? null) {
                $out[] = ['rank' => count($out) + 1, 'user' => $user, 'ms' => $row['ms'], 'plays' => $row['plays']];
            }
        }

        return $out;
    }

    /** Who is listening right now, newest first. */
    public function nowPlaying(User $actor, int $limit): array
    {
        $scope = Access::scope($actor);
        $rows = $this->cache->remember('rotation.now.'.implode('-', $scope).".$limit", 60, fn () => $this->db->table('rotation_profiles')
            ->where('now_playing_at', '>=', CarbonImmutable::now('UTC')->subMinutes(self::LIVE_MINUTES)->toDateTimeString())
            ->where('show_now_playing', true)->whereIn('visibility', $scope)
            ->orderByDesc('now_playing_at')
            ->limit($limit)->get(['user_id', 'now_playing'])
            ->map(fn ($r) => ['id' => (int) $r->user_id, 'track' => json_decode((string) $r->now_playing, true)])->all());

        $users = $this->users($actor, array_column($rows, 'id'));
        $out = [];
        foreach ($rows as $row) {
            if (($user = $users[$row['id']] ?? null) && is_array($row['track'])) {
                $out[] = ['user' => $user, 'track' => $row['track']];
            }
        }

        return $out;
    }

    /**
     * The people in a list, in one query, as this viewer may see them.
     *
     * @param list<int> $ids
     * @return array<int, array>
     */
    public function users(User $actor, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $out = [];
        foreach (User::query()->whereVisibleTo($actor)->whereIn('id', $ids)->get() as $user) {
            $out[$user->id] = $this->person($user);
        }

        return $out;
    }

    public function person(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'avatarUrl' => $user->avatar_url,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
            'level' => $user->getAttribute('rotation_level') !== null ? (int) $user->getAttribute('rotation_level') : null,
        ];
    }

    /** Last.fm's page for an artist or a song: the link-back its terms ask for. */
    public static function lastfmUrl(string $artist, ?string $title = null): string
    {
        $enc = fn (string $s) => str_replace('%2B', '%252B', urlencode($s));

        return 'https://www.last.fm/music/'.$enc($artist).($title !== null ? '/_/'.$enc($title) : '');
    }

    private function since(string $period): ?string
    {
        // 🚨 Not `?? 7`: "all" maps to null on purpose, and ?? would make it a week.
        $days = array_key_exists($period, self::PERIODS) ? self::PERIODS[$period] : 7;

        return $days === null ? null : CarbonImmutable::now('UTC')->startOfDay()->subDays($days - 1)->toDateString();
    }

    /** Forget cached boards after a member's choices change. */
    public function forget(): void
    {
        foreach (array_keys(self::PERIODS) as $period) {
            foreach ([5, 10, 25, 50] as $limit) {
                foreach (['everyone', 'everyone-members'] as $scope) {
                    $this->cache->forget("rotation.listeners.$scope.$period.$limit");
                }
                foreach (['t', 'a'] as $kind) {
                    $this->cache->forget("rotation.chart.$kind.$period.$limit");
                }
            }
        }
        foreach ([5, 10, 25, 50] as $limit) {
            $this->cache->forget("rotation.now.everyone.$limit");
            $this->cache->forget("rotation.now.everyone-members.$limit");
        }
    }
}
