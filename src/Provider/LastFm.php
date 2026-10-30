<?php

namespace Ernestdefoe\Rotation\Provider;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Budget;
use Ernestdefoe\Rotation\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Last.fm, which already collects plays from Spotify, Apple Music, YouTube
 * Music and the Web Scrobbler browser extension.
 *
 * Web auth proves which account a member owns; after that every read is a
 * public API call with the forum's key, so the session key Last.fm hands back
 * is used once and never stored.
 *
 * 🚨 Last.fm's licence excludes its images, so no artwork is ever read from it.
 */
class LastFm implements ListeningProvider
{
    private const API = 'https://ws.audioscrobbler.com/2.0/';
    private const PAGE = 200;
    /** A first poll reaches back this far; later polls ask for plays after the last one stored. */
    public const FIRST_DAYS = 14;
    /** Pages per poll. A backlog bigger than this is caught up over the next polls, oldest first. */
    private const PAGES = 4;

    private ?Client $client = null;

    public function __construct(private Settings $settings, private Budget $budget)
    {
    }

    public function key(): string
    {
        return 'lastfm';
    }

    public function configured(): bool
    {
        return $this->settings->configured();
    }

    public function authorizeUrl(string $callback): string
    {
        return 'https://www.last.fm/api/auth/?'.http_build_query(['api_key' => $this->settings->lastfmKey(), 'cb' => $callback]);
    }

    public function complete(array $query): string
    {
        $token = (string) ($query['token'] ?? '');
        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) {
            throw new ProviderException(ProviderException::DENIED);
        }

        $data = $this->call(['method' => 'auth.getSession', 'token' => $token], true);
        $name = (string) ($data['session']['name'] ?? '');

        if ($name === '') {
            throw new ProviderException(ProviderException::DENIED);
        }

        return $name;
    }

    public function recent(string $account, ?CarbonImmutable $since): Listening
    {
        // From the last stored play's own second, not the one after: two plays can
        // share a second, and the unique key drops the one already stored.
        $from = $since ? $since->getTimestamp() : CarbonImmutable::now()->subDays(self::FIRST_DAYS)->getTimestamp();
        $params = ['method' => 'user.getRecentTracks', 'user' => $account, 'limit' => self::PAGE, 'from' => $from];

        $first = $this->call($params + ['page' => 1]);
        $items = $this->items($first['recenttracks']['track'] ?? []);
        $total = max(1, (int) ($first['recenttracks']['@attr']['totalPages'] ?? 1));

        $nowPlaying = null;
        $recent = [];
        foreach ($items as $item) {
            $track = $this->track($item);
            if (! $track) {
                continue;
            }
            if (($item['@attr']['nowplaying'] ?? null) === 'true') {
                $nowPlaying ??= ['artist' => $track['artist'], 'title' => $track['title'], 'url' => $track['url']];
            } elseif (count($recent) < 10 && isset($item['date']['uts'])) {
                $recent[] = ['artist' => $track['artist'], 'title' => $track['title'], 'url' => $track['url'], 'playedAt' => CarbonImmutable::createFromTimestamp((int) $item['date']['uts'])->toIso8601String()];
            }
        }

        // A first poll with nothing in the last two weeks still shows the
        // member's latest plays, however old: for display, never counted.
        if ($since === null && ! $recent && ! $nowPlaying) {
            $latest = $this->call(['method' => 'user.getRecentTracks', 'user' => $account, 'limit' => 10]);
            foreach ($this->items($latest['recenttracks']['track'] ?? []) as $item) {
                if (($track = $this->track($item)) && isset($item['date']['uts']) && count($recent) < 10) {
                    $recent[] = ['artist' => $track['artist'], 'title' => $track['title'], 'url' => $track['url'], 'playedAt' => CarbonImmutable::createFromTimestamp((int) $item['date']['uts'])->toIso8601String()];
                }
            }
        }

        /*
         * A backlog bigger than one poll takes is read from its OLDEST end, so
         * the stored-up-to mark only ever moves past plays that were stored.
         * The newest page waits for the next poll, which comes a minute later.
         */
        if ($total <= self::PAGES) {
            // range(2, 1) counts DOWN in PHP: one page means no more pages.
            $pages = $total > 1 ? range(2, $total) : [];
            $plays = $this->plays($items);
        } else {
            $pages = range($total - self::PAGES + 1, $total);
            $plays = [];
        }

        foreach ($pages as $page) {
            $data = $this->call($params + ['page' => $page]);
            $plays = array_merge($plays, $this->plays($this->items($data['recenttracks']['track'] ?? [])));
        }

        return new Listening($plays, $nowPlaying, $recent, $total > self::PAGES);
    }

    public function tops(string $account): array
    {
        $tops = [];

        foreach (['month' => '1month', 'overall' => 'overall'] as $key => $period) {
            $tracks = $this->call(['method' => 'user.getTopTracks', 'user' => $account, 'period' => $period, 'limit' => 10]);
            $artists = $this->call(['method' => 'user.getTopArtists', 'user' => $account, 'period' => $period, 'limit' => 10]);

            $tops[$key] = [
                'tracks' => array_values(array_filter(array_map(fn ($t) => is_array($t) && isset($t['name']) ? [
                    'title' => $this->text($t['name']),
                    'artist' => $this->text($t['artist']['name'] ?? ''),
                    'plays' => (int) ($t['playcount'] ?? 0),
                    'url' => $this->url($t['url'] ?? null),
                ] : null, $this->items($tracks['toptracks']['track'] ?? [])))),
                'artists' => array_values(array_filter(array_map(fn ($a) => is_array($a) && isset($a['name']) ? [
                    'name' => $this->text($a['name']),
                    'plays' => (int) ($a['playcount'] ?? 0),
                    'url' => $this->url($a['url'] ?? null),
                ] : null, $this->items($artists['topartists']['artist'] ?? [])))),
            ];
        }

        return $tops;
    }

    public function trackLength(string $artist, string $title, ?string $mbid): ?int
    {
        try {
            $data = $this->call(['method' => 'track.getInfo', 'artist' => $artist, 'track' => $title, 'autocorrect' => 1] + ($mbid ? ['mbid' => $mbid] : []));
        } catch (ProviderException $e) {
            // "Track not found" is an answer, not a failure.
            if ($e->kind === ProviderException::MISSING) {
                return null;
            }
            throw $e;
        }

        $ms = (int) ($data['track']['duration'] ?? 0);

        return $ms >= 10000 && $ms <= 3600000 ? $ms : null;
    }

    public function profileUrl(string $account): string
    {
        return 'https://www.last.fm/user/'.rawurlencode($account);
    }

    /** @return list<array{artist: string, title: string, artistMbid: ?string, mbid: ?string, playedAt: CarbonImmutable}> */
    private function plays(array $items): array
    {
        $plays = [];

        foreach ($items as $item) {
            if (($item['@attr']['nowplaying'] ?? null) === 'true' || ! isset($item['date']['uts'])) {
                continue;
            }
            if ($track = $this->track($item)) {
                $plays[] = [
                    'artist' => $track['artist'],
                    'title' => $track['title'],
                    'artistMbid' => $this->mbid($item['artist']['mbid'] ?? null),
                    'mbid' => $this->mbid($item['mbid'] ?? null),
                    'playedAt' => CarbonImmutable::createFromTimestamp((int) $item['date']['uts']),
                ];
            }
        }

        return $plays;
    }

    /** @return array{artist: string, title: string, url: ?string}|null */
    private function track(mixed $item): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $artist = $this->text($item['artist']['#text'] ?? $item['artist']['name'] ?? '');
        $title = $this->text($item['name'] ?? '');

        return $artist === '' || $title === '' ? null : ['artist' => $artist, 'title' => $title, 'url' => $this->url($item['url'] ?? null)];
    }

    /** Last.fm sends one item as an object and several as a list. */
    private function items(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 255) : '';
    }

    private function mbid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) ? $value : null;
    }

    /** Only links to Last.fm itself are kept: they are shown to readers. */
    private function url(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^https://www\.last\.fm/[^\s"<>]{1,500}$~', $value) ? $value : null;
    }

    private function call(array $params, bool $signed = false): array
    {
        if (! $this->configured()) {
            throw new ProviderException(ProviderException::BAD_KEY, 'not configured');
        }
        if (! $this->budget->take('lastfm', $this->settings->rate())) {
            throw new ProviderException(ProviderException::BUDGET);
        }

        $params['api_key'] = $this->settings->lastfmKey();
        if ($signed) {
            $params['api_sig'] = $this->signature($params);
        }
        $params['format'] = 'json';

        try {
            $response = $this->client()->request($signed ? 'POST' : 'GET', self::API, $signed ? ['form_params' => $params] : ['query' => $params]);
        } catch (GuzzleException $e) {
            throw new ProviderException(ProviderException::TRANSIENT, 'network');
        }

        $data = json_decode((string) $response->getBody(), true);

        if (is_array($data) && isset($data['error'])) {
            throw new ProviderException(match ((int) $data['error']) {
                6 => ProviderException::MISSING,
                17 => ProviderException::PRIVATE,
                29 => ProviderException::RATE_LIMITED,
                10, 26 => ProviderException::BAD_KEY,
                4, 14, 15 => ProviderException::DENIED,
                default => ProviderException::TRANSIENT,
            }, 'lastfm '.(int) $data['error']);
        }

        if ($response->getStatusCode() === 429) {
            throw new ProviderException(ProviderException::RATE_LIMITED, 'http 429');
        }
        if ($response->getStatusCode() >= 400 || ! is_array($data)) {
            throw new ProviderException(ProviderException::TRANSIENT, 'http '.$response->getStatusCode());
        }

        return $data;
    }

    /** Every parameter but `format`, sorted by name, then the secret: Last.fm's own scheme. */
    private function signature(array $params): string
    {
        ksort($params);
        $base = '';
        foreach ($params as $key => $value) {
            $base .= $key.$value;
        }

        return md5($base.$this->settings->lastfmSecret());
    }

    private function client(): Client
    {
        return $this->client ??= new Client([
            'timeout' => 8,
            'connect_timeout' => 4,
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => ['User-Agent' => 'Rotation for Flarum (+https://github.com/ernestdefoe/rotation)'],
        ]);
    }
}
