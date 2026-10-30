<?php

namespace Ernestdefoe\Rotation;

use Ernestdefoe\Rotation\Provider\ProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Track lengths Last.fm does not know, from MusicBrainz.
 *
 * 🚨 MusicBrainz allows one request a second per IP and answers anything
 * faster with a 503 for every request, so requests are spaced a little wider
 * than that, and a 503 leaves it alone for ten minutes.
 */
class MusicBrainz
{
    private const API = 'https://musicbrainz.org/ws/2/';

    private ?Client $client = null;

    public function __construct(private Budget $budget)
    {
    }

    /** @throws ProviderException */
    public function length(string $artist, string $title, ?string $mbid): ?int
    {
        if ($mbid !== null) {
            $data = $this->get('recording/'.$mbid, []);
            $ms = (int) ($data['length'] ?? 0);
            if ($ms > 0) {
                return $this->sane($ms);
            }
        }

        $data = $this->get('recording', [
            'query' => 'recording:"'.$this->escape($title).'" AND artist:"'.$this->escape($artist).'"',
            'limit' => 3,
        ]);

        foreach ($data['recordings'] ?? [] as $recording) {
            // Only a confident match: a wrong length is worse than the average.
            if ((int) ($recording['score'] ?? 0) >= 90 && (int) ($recording['length'] ?? 0) > 0) {
                return $this->sane((int) $recording['length']);
            }
        }

        return null;
    }

    private function sane(int $ms): ?int
    {
        return $ms >= 10000 && $ms <= 3600000 ? $ms : null;
    }

    /** Lucene's special characters, escaped so a title cannot change the query. */
    private function escape(string $value): string
    {
        return preg_replace('/([+\-&|!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', $value) ?? '';
    }

    private function get(string $path, array $query): array
    {
        if (! $this->budget->take('musicbrainz', 0.9)) {
            throw new ProviderException(ProviderException::BUDGET);
        }

        try {
            $response = $this->client()->get(self::API.$path, ['query' => $query + ['fmt' => 'json']]);
        } catch (GuzzleException $e) {
            throw new ProviderException(ProviderException::TRANSIENT, 'network');
        }

        $status = $response->getStatusCode();
        if ($status === 503 || $status === 429) {
            $this->budget->pause('musicbrainz', 600);
            throw new ProviderException(ProviderException::RATE_LIMITED, "musicbrainz $status");
        }
        if ($status === 404) {
            return [];
        }
        if ($status >= 400) {
            throw new ProviderException(ProviderException::TRANSIENT, "musicbrainz $status");
        }

        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    private function client(): Client
    {
        return $this->client ??= new Client([
            'timeout' => 8,
            'connect_timeout' => 4,
            'http_errors' => false,
            'allow_redirects' => false,
            // MusicBrainz requires a User-Agent that says who is asking.
            'headers' => ['User-Agent' => 'Rotation-for-Flarum/1.0 ( https://github.com/ernestdefoe/rotation )', 'Accept' => 'application/json'],
        ]);
    }
}
