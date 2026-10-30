<?php

namespace Ernestdefoe\Rotation\Embed;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * The only way Rotation fetches an address that came from a post.
 *
 * 🚨 Server-side request forgery is the risk: a post is someone else's input,
 * and the forum's server sits inside a network. So: HTTPS on port 443 only;
 * the host must pass the caller's allow-list; every address the name resolves
 * to must be public (no private, loopback, link-local or reserved ranges); the
 * connection is pinned to the address that was checked, so DNS cannot answer
 * differently a moment later; at most two redirects, each to an allow-listed
 * host and checked again; 3 seconds; and at most 256 KB is read.
 */
class SafeFetcher
{
    private const MAX_BYTES = 262144;

    /** @param callable(string): bool $allowHost */
    public function get(string $url, callable $allowHost, int $hops = 2): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($parts['port'] ?? 443, [443], true) || ! $allowHost($host)) {
            return null;
        }

        // The pin below is a curl option: without curl there is no pin, so no fetch.
        $ip = function_exists('curl_init') ? $this->publicAddress($host) : null;
        if ($ip === null) {
            return null;
        }

        try {
            $response = (new Client())->get($url, [
                'timeout' => 3,
                'connect_timeout' => 3,
                'http_errors' => false,
                'allow_redirects' => false,
                'stream' => true,
                'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; Rotation for Flarum)', 'Accept' => 'text/html'],
                'curl' => [CURLOPT_RESOLVE => ["$host:443:".(str_contains($ip, ':') ? "[$ip]" : $ip)]],
            ]);
        } catch (GuzzleException $e) {
            return null;
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            $location = $response->getHeaderLine('Location');

            return $hops > 0 && str_starts_with($location, 'https://') ? $this->get($location, $allowHost, $hops - 1) : null;
        }
        if ($status !== 200) {
            return null;
        }

        $body = $response->getBody();
        $out = '';
        while (! $body->eof() && strlen($out) < self::MAX_BYTES) {
            $out .= $body->read(min(8192, self::MAX_BYTES - strlen($out)));
        }
        $body->close();

        return $out;
    }

    /** One public address for the host, or null when any of its addresses is not public. */
    private function publicAddress(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));

        foreach ($ips as $ip) {
            // GLOBAL_RANGE also rules out CGNAT, documentation and IPv4-mapped IPv6 addresses.
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        // IPv4 first: not every server has an IPv6 route out.
        usort($ips, fn ($a, $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return $ips[0] ?? null;
    }
}
