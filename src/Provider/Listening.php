<?php

namespace Ernestdefoe\Rotation\Provider;

use Carbon\CarbonImmutable;

/** What one poll found. */
final class Listening
{
    /**
     * @param list<array{artist: string, title: string, artistMbid: ?string, mbid: ?string, playedAt: CarbonImmutable}> $plays
     * @param array{artist: string, title: string, url: ?string}|null $nowPlaying
     * @param list<array{artist: string, title: string, url: ?string, playedAt: string}> $recent
     * @param bool $more a backlog is left for the next poll
     */
    public function __construct(public array $plays, public ?array $nowPlaying, public array $recent, public bool $more = false)
    {
    }
}
