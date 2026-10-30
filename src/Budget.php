<?php

namespace Ernestdefoe\Rotation;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * How many requests Rotation may send, and how fast.
 *
 * 🚨 Several forums can share one outbound IP, and Last.fm (5 requests a
 * second) and MusicBrainz (1 a second) limit per IP, so this forum stays well
 * under both: requests are spaced to the admin's rate, every service together
 * stays under a cap per 10 minutes, and a service that says "slow down" is left
 * alone for a while. Only the poller sends requests, and it never overlaps
 * itself, so spacing within the process is spacing for the forum.
 */
class Budget
{
    /** @var array<string, float> */
    private array $last = [];

    public function __construct(private Cache $cache, private Settings $settings)
    {
    }

    /** Wait for this service's turn and count the request. False: nothing may be sent now. */
    public function take(string $service, float $perSecond): bool
    {
        if ($this->paused($service)) {
            return false;
        }

        $window = 'rotation.calls.'.intdiv(time(), 600);
        $this->cache->add($window, 0, 660);
        if ((int) $this->cache->increment($window) > $this->settings->globalCap()) {
            return false;
        }

        $gap = 1 / $perSecond;
        $wait = ($this->last[$service] ?? 0) + $gap - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $this->last[$service] = microtime(true);

        return true;
    }

    public function pause(string $service, int $seconds): void
    {
        $this->cache->put("rotation.pause.$service", 1, $seconds);
    }

    public function paused(string $service): bool
    {
        return (bool) $this->cache->get("rotation.pause.$service");
    }
}
