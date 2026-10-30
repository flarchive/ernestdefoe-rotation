<?php

namespace Ernestdefoe\Rotation\Embed;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Bandcamp's player needs the album's or track's number, which only its page
 * holds. The page is read once, in the queue, through SafeFetcher, and the
 * number cached. Artists on their own domains are not covered: their links
 * stay links.
 */
class Bandcamp
{
    public const PATTERN = '~^https://([a-z0-9-]{1,63})\.bandcamp\.com/(album|track)/([a-z0-9-]{1,200})/?$~i';
    private const FIND = '~https://[a-z0-9-]{1,63}\.bandcamp\.com/(?:album|track)/[a-z0-9-]{1,200}~i';

    public function __construct(private ConnectionInterface $db, private SafeFetcher $fetcher)
    {
    }

    /** The one spelling of a link the cache is keyed by. */
    public static function normalize(string $url): ?string
    {
        $url = preg_replace('~[?#].*$~', '', trim($url)) ?? '';

        return preg_match(self::PATTERN, $url, $m) ? 'https://'.strtolower($m[1]).'.bandcamp.com/'.strtolower($m[2]).'/'.strtolower($m[3]) : null;
    }

    public static function hash(string $normalized): string
    {
        return sha1($normalized);
    }

    /** Bandcamp links in a post's stored content. @return list<string> normalized */
    public static function find(string $content): array
    {
        if (! str_contains($content, 'bandcamp.com')) {
            return [];
        }
        preg_match_all(self::FIND, $content, $m);

        return array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $m[0]))));
    }

    /** Look up the links not already known. @param list<string> $urls normalized */
    public function resolve(array $urls): void
    {
        $known = $this->db->table('rotation_embeds')->whereIn('url_hash', array_map([self::class, 'hash'], $urls))->pluck('url_hash')->all();

        foreach ($urls as $url) {
            $hash = self::hash($url);
            if (in_array($hash, $known, true)) {
                continue;
            }

            $html = $this->fetcher->get($url, fn (string $host) => (bool) preg_match('/^[a-z0-9-]{1,63}\.bandcamp\.com$/', $host));
            $kind = $ref = null;
            if ($html !== null && preg_match('~name="bc-page-properties"\s+content="([^"]{1,500})"~', $html, $m)) {
                $props = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
                $id = $props['item_id'] ?? null;
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $kind = ($props['item_type'] ?? '') === 't' ? 'track' : 'album';
                    $ref = (string) $id;
                }
            }

            $this->db->table('rotation_embeds')->upsert(
                [['url_hash' => $hash, 'kind' => $kind, 'ref' => $ref, 'resolved_at' => CarbonImmutable::now('UTC')->toDateTimeString()]],
                ['url_hash'],
                ['kind', 'ref', 'resolved_at']
            );
        }
    }
}
