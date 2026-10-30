<?php

namespace Ernestdefoe\Rotation;

use Illuminate\Database\ConnectionInterface;

/**
 * Artists and tracks, one row each however many plays and members point at
 * them. A batch of plays costs the same handful of queries whatever its size.
 */
class Catalog
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public static function norm(string $value): string
    {
        $value = class_exists(\Normalizer::class) ? (\Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value) : $value;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    public static function artistKey(string $artist): string
    {
        return sha1(self::norm($artist));
    }

    public static function trackKey(string $artist, string $title): string
    {
        return sha1(self::norm($artist)."\0".self::norm($title));
    }

    /**
     * The track and artist ids for these plays, creating what is new.
     *
     * @param list<array{artist: string, title: string, artistMbid?: ?string, mbid?: ?string}> $plays
     * @return array<string, array{track: int, artist: int}> by trackKey
     */
    public function ids(array $plays): array
    {
        $artists = [];
        $tracks = [];
        foreach ($plays as $play) {
            $artists[self::artistKey($play['artist'])] ??= ['name' => $play['artist'], 'mbid' => $play['artistMbid'] ?? null];
            $tracks[self::trackKey($play['artist'], $play['title'])] ??= $play;
        }

        $artistIds = $this->upsert('rotation_artists', $artists, fn (string $norm, array $a) => [
            'name' => $a['name'], 'norm' => $norm, 'mbid' => $a['mbid'],
        ]);

        $trackIds = $this->upsert('rotation_tracks', $tracks, fn (string $norm, array $t) => [
            'artist_id' => $artistIds[self::artistKey($t['artist'])],
            'title' => $t['title'],
            'norm' => $norm,
            'mbid' => $t['mbid'] ?? null,
        ]);

        $ids = [];
        foreach ($tracks as $norm => $track) {
            $ids[$norm] = ['track' => $trackIds[$norm], 'artist' => $artistIds[self::artistKey($track['artist'])]];
        }

        return $ids;
    }

    /**
     * @param array<string, array> $wanted by norm
     * @return array<string, int> id by norm
     */
    private function upsert(string $table, array $wanted, callable $row): array
    {
        $ids = [];

        foreach (array_chunk(array_keys($wanted), 500) as $chunk) {
            $ids += $this->db->table($table)->whereIn('norm', $chunk)->pluck('id', 'norm')->map(fn ($id) => (int) $id)->all();

            $missing = array_values(array_diff($chunk, array_keys($ids)));
            if ($missing) {
                // insertOrIgnore: another forum process may add the same song at the same moment.
                $this->db->table($table)->insertOrIgnore(array_map(fn ($norm) => $row($norm, $wanted[$norm]), $missing));
                $ids += $this->db->table($table)->whereIn('norm', $missing)->pluck('id', 'norm')->map(fn ($id) => (int) $id)->all();
            }
        }

        return $ids;
    }
}
