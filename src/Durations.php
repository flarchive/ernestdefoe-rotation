<?php

namespace Ernestdefoe\Rotation;

use Ernestdefoe\Rotation\Provider\ProviderException;
use Ernestdefoe\Rotation\Provider\Providers;
use Illuminate\Database\ConnectionInterface;

/**
 * Track lengths, looked up once per song and cached on the track: Last.fm
 * first, then MusicBrainz. A song neither knows counts as the admin's average
 * — which is also what it counts as until it has been looked up, so a new
 * song's plays are never missing from anyone's time, only refined later.
 */
class Durations
{
    private const BATCH = 60;
    private const TRIES = 3;

    public function __construct(
        private ConnectionInterface $db,
        private Providers $providers,
        private MusicBrainz $musicBrainz,
        private Rollups $rollups,
    ) {
    }

    /** Look up lengths until the deadline or the budget. Returns how many songs were settled. */
    public function resolve(float $deadline): int
    {
        $lastfm = $this->providers->get('lastfm');
        if (! $lastfm || ! $lastfm->configured()) {
            return 0;
        }

        $tracks = $this->db->table('rotation_tracks as t')
            ->join('rotation_artists as a', 'a.id', '=', 't.artist_id')
            ->whereNull('t.duration_source')
            ->orderBy('t.id')
            ->limit(self::BATCH)
            ->get(['t.id', 't.title', 't.mbid', 't.duration_tries', 'a.name as artist']);

        $settled = 0;
        $found = [];

        foreach ($tracks as $track) {
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                $source = 'lastfm';
                $ms = $lastfm->trackLength((string) $track->artist, (string) $track->title, $track->mbid);
                if ($ms === null) {
                    $source = 'musicbrainz';
                    $ms = $this->musicBrainz->length((string) $track->artist, (string) $track->title, $track->mbid);
                }

                $this->db->table('rotation_tracks')->where('id', $track->id)->update([
                    'duration_ms' => $ms,
                    'duration_source' => $ms === null ? 'none' : $source,
                ]);
                $settled++;
                if ($ms !== null) {
                    $found[] = (int) $track->id;
                }
            } catch (ProviderException $e) {
                if (in_array($e->kind, [ProviderException::BUDGET, ProviderException::RATE_LIMITED, ProviderException::BAD_KEY], true)) {
                    break;
                }
                $tries = (int) $track->duration_tries + 1;
                $this->db->table('rotation_tracks')->where('id', $track->id)->update(
                    ['duration_tries' => $tries] + ($tries >= self::TRIES ? ['duration_source' => 'none'] : [])
                );
            }
        }

        $this->refresh($found);

        return $settled;
    }

    /** Re-add up the days whose plays include these songs, now their lengths are known. @param list<int> $trackIds */
    private function refresh(array $trackIds): void
    {
        if (! $trackIds) {
            return;
        }

        $days = [];
        $this->db->table('rotation_plays')->whereIn('track_id', $trackIds)
            ->where('played_at', '>=', $this->rollups->cutoff()->toDateTimeString())
            ->select(['user_id', 'played_at'])
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$days) {
                foreach ($rows as $row) {
                    $days[(int) $row->user_id][substr((string) $row->played_at, 0, 10)] = true;
                }
            });

        foreach ($days as $userId => $set) {
            $this->rollups->refreshUser($userId, array_keys($set));
        }
    }
}
