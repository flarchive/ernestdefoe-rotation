<?php

namespace Ernestdefoe\Rotation;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Every admin choice, read with its default and clamped to something sane, so
 * a typo in the admin can never stop polling or divide by zero.
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-rotation.';

    /** Players Rotation can build from a link, in the order the admin lists them. */
    public const EMBEDS = ['spotify', 'apple', 'youtube', 'soundcloud', 'deezer', 'tidal', 'bandcamp', 'audiomack', 'mixcloud'];

    /** "From 0 to 10 hours = level 1"… The admin replaces these on the first save. */
    public const DEFAULT_BANDS = [
        ['from' => 0, 'to' => 10, 'level' => 1, 'name' => 'Tuning in'],
        ['from' => 10, 'to' => 50, 'level' => 2, 'name' => 'Regular'],
        ['from' => 50, 'to' => 150, 'level' => 3, 'name' => 'Devoted'],
        ['from' => 150, 'to' => 400, 'level' => 4, 'name' => 'Crate digger'],
        ['from' => 400, 'to' => 1000, 'level' => 5, 'name' => 'Audiophile'],
        ['from' => 1000, 'to' => null, 'level' => 6, 'name' => 'Legend'],
    ];

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings->get(self::PREFIX.$key, $default);
    }

    public function lastfmKey(): string
    {
        return trim((string) $this->get('lastfm_key'));
    }

    public function lastfmSecret(): string
    {
        return trim((string) $this->get('lastfm_secret'));
    }

    public function configured(): bool
    {
        return $this->lastfmKey() !== '' && $this->lastfmSecret() !== '';
    }

    /** A track whose length nobody knows counts as this long. */
    public function averageMs(): int
    {
        return (int) round($this->clamp((float) $this->get('average_minutes', 3.5), 0.5, 20) * 60000);
    }

    /** No member's day counts for more listening than this (fake scrobbles). */
    public function dailyCapMs(): int
    {
        return (int) round($this->clamp((float) $this->get('daily_cap_hours', 16), 1, 24) * 3600000);
    }

    /** Days raw plays are kept. Rollups, totals and levels outlive them. */
    public function retentionDays(): int
    {
        return (int) $this->clamp((int) $this->get('retention_days', 90), 14, 3650);
    }

    /** Last.fm requests per second from this forum. Its limit is 5 per IP, shared with every forum on the box. */
    public function rate(): float
    {
        return $this->clamp((float) $this->get('rate', 1), 0.2, 4);
    }

    /** Outbound requests per 10 minutes, every service together. */
    public function globalCap(): int
    {
        return (int) $this->clamp((int) $this->get('global_cap', 300), 10, 3000);
    }

    public function defaultVisibility(): string
    {
        $v = (string) $this->get('default_visibility', 'everyone');

        return in_array($v, ['everyone', 'members', 'me'], true) ? $v : 'everyone';
    }

    /** @return list<string> */
    public function embeds(): array
    {
        $raw = $this->get('embeds');
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($list) ? array_values(array_intersect(self::EMBEDS, $list)) : self::EMBEDS;
    }

    /**
     * The level bands in the admin's order. A band with no upper bound runs
     * on forever; the first band an amount falls in wins.
     *
     * @return list<array{from: float, to: float|null, level: int, name: string}>
     */
    public function bands(): array
    {
        $raw = $this->get('bands');
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $bands = [];

        foreach (is_array($list) ? $list : self::DEFAULT_BANDS as $band) {
            if (! is_array($band) || ! is_numeric($band['from'] ?? null) || ! is_numeric($band['level'] ?? null)) {
                continue;
            }
            $to = $band['to'] ?? null;
            $bands[] = [
                'from' => max(0.0, (float) $band['from']),
                'to' => is_numeric($to) ? (float) $to : null,
                'level' => max(0, min(65535, (int) $band['level'])),
                'name' => mb_substr(trim((string) ($band['name'] ?? '')), 0, 40),
            ];
        }

        return $bands;
    }

    private function clamp(float|int $value, float|int $min, float|int $max): float|int
    {
        return max($min, min($max, $value));
    }
}
