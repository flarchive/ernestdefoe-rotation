<?php

namespace Ernestdefoe\Rotation\Tests\integration\api;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Embed\Bandcamp;
use Ernestdefoe\Rotation\Embed\SafeFetcher;
use Ernestdefoe\Rotation\Tests\integration\RotationTestCase;
use PHPUnit\Framework\Attributes\Test;

class EmbedsTest extends RotationTestCase
{
    #[Test]
    public function bandcamp_links_are_found_and_normalised(): void
    {
        $this->assertSame(
            ['https://artist.bandcamp.com/album/some-record'],
            Bandcamp::find('<r><URL url="https://Artist.bandcamp.com/album/some-record?from=x">x</URL> https://artist.bandcamp.com/album/some-record</r>')
        );
        $this->assertSame([], Bandcamp::find('https://evil.example/album/x https://bandcamp.com.evil.example/album/x'));
    }

    #[Test]
    public function the_endpoint_only_reads_the_cache(): void
    {
        $url = 'https://artist.bandcamp.com/album/some-record';
        $this->db()->table('rotation_embeds')->insert(['url_hash' => Bandcamp::hash($url), 'kind' => 'album', 'ref' => '123', 'resolved_at' => CarbonImmutable::now()->toDateTimeString()]);

        $response = $this->send($this->request('GET', '/api/rotation/embeds')->withQueryParams(['urls' => [$url, 'https://other.bandcamp.com/track/unknown']]));
        $data = json_decode((string) $response->getBody(), true)['data'];

        $this->assertSame([$url => ['kind' => 'album', 'id' => '123']], $data);
    }

    #[Test]
    public function the_fetcher_refuses_private_and_non_https_addresses(): void
    {
        $fetcher = new SafeFetcher();
        $any = fn () => true;

        $this->assertNull($fetcher->get('http://example.com/', $any));
        $this->assertNull($fetcher->get('https://localhost/', $any));
        $this->assertNull($fetcher->get('https://127.0.0.1/', $any));
        $this->assertNull($fetcher->get('https://example.com:8443/', $any));
        $this->assertNull($fetcher->get('https://user:pw@example.com/', $any));
        $this->assertNull($fetcher->get('https://example.com/', fn () => false));
    }
}
