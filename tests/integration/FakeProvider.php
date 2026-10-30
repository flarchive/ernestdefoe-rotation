<?php

namespace Ernestdefoe\Rotation\Tests\integration;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Provider\Listening;
use Ernestdefoe\Rotation\Provider\ListeningProvider;
use Ernestdefoe\Rotation\Provider\ProviderException;

/** Last.fm, as the tests want it to answer. Never touches the network. */
class FakeProvider implements ListeningProvider
{
    public static array $plays = [];
    public static ?array $nowPlaying = null;
    public static ?string $fail = null;
    public static string $account = 'fakeuser';
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$plays = [];
        self::$nowPlaying = null;
        self::$fail = null;
        self::$account = 'fakeuser';
        self::$calls = 0;
    }

    public function key(): string
    {
        return 'lastfm';
    }

    public function configured(): bool
    {
        return true;
    }

    public function authorizeUrl(string $callback): string
    {
        return 'https://www.last.fm/api/auth/?cb='.urlencode($callback);
    }

    public function complete(array $query): string
    {
        if (($query['token'] ?? '') !== 'good-token') {
            throw new ProviderException(ProviderException::DENIED);
        }

        return self::$account;
    }

    public function recent(string $account, ?CarbonImmutable $since): Listening
    {
        self::$calls++;
        if (self::$fail) {
            throw new ProviderException(self::$fail);
        }

        $plays = array_map(fn ($p) => $p + ['artistMbid' => null, 'mbid' => null], self::$plays);

        return new Listening($plays, self::$nowPlaying, []);
    }

    public function tops(string $account): array
    {
        return ['month' => ['tracks' => [], 'artists' => []], 'overall' => ['tracks' => [], 'artists' => []]];
    }

    public function trackLength(string $artist, string $title, ?string $mbid): ?int
    {
        return null;
    }

    public function profileUrl(string $account): string
    {
        return 'https://www.last.fm/user/'.$account;
    }
}
