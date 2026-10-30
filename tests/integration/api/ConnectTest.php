<?php

namespace Ernestdefoe\Rotation\Tests\integration\api;

use Ernestdefoe\Rotation\Tests\integration\FakeProvider;
use Ernestdefoe\Rotation\Tests\integration\RotationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ConnectTest extends RotationTestCase
{
    /** Start connecting as user 2; returns the callback path and the response holding the session. */
    private function start(): array
    {
        $response = $this->send($this->request('GET', '/rotation/connect/lastfm', ['authenticatedAs' => 2]));
        $this->assertSame(302, $response->getStatusCode());

        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $path = (string) parse_url((string) $query['cb'], PHP_URL_PATH);
        $this->assertMatchesRegularExpression('~/rotation/callback/lastfm/[A-Za-z0-9]{40}$~', $path);

        return [$path, $response];
    }

    #[Test]
    public function a_member_links_the_account_lastfm_vouches_for(): void
    {
        [$path, $session] = $this->start();

        $response = $this->send($this->request('GET', $path, ['cookiesFrom' => $session])->withQueryParams(['token' => 'good-token']));

        $this->assertStringContainsString('rotation=connected', $response->getHeaderLine('Location'));
        $this->assertSame('fakeuser', $this->db()->table('rotation_accounts')->where('user_id', 2)->value('external_name'));
    }

    #[Test]
    public function a_forged_state_links_nothing(): void
    {
        [, $session] = $this->start();

        $response = $this->send($this->request('GET', '/rotation/callback/lastfm/'.str_repeat('a', 40), ['cookiesFrom' => $session])->withQueryParams(['token' => 'good-token']));

        $this->assertStringContainsString('rotation=expired', $response->getHeaderLine('Location'));
        $this->assertSame(0, $this->db()->table('rotation_accounts')->count());
    }

    #[Test]
    public function a_state_works_once(): void
    {
        [$path, $session] = $this->start();
        $this->send($this->request('GET', $path, ['cookiesFrom' => $session])->withQueryParams(['token' => 'good-token']));
        $this->db()->table('rotation_accounts')->delete();

        $response = $this->send($this->request('GET', $path, ['cookiesFrom' => $session])->withQueryParams(['token' => 'good-token']));

        $this->assertStringContainsString('rotation=expired', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function an_account_linked_by_someone_else_stays_theirs(): void
    {
        $this->connect(3, 'fakeuser');
        [$path, $session] = $this->start();

        $response = $this->send($this->request('GET', $path, ['cookiesFrom' => $session])->withQueryParams(['token' => 'good-token']));

        $this->assertStringContainsString('rotation=taken', $response->getHeaderLine('Location'));
        $this->assertSame(3, (int) $this->db()->table('rotation_accounts')->where('external_name', 'fakeuser')->value('user_id'));
    }

    #[Test]
    public function connect_attempts_are_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->start();
        }

        $response = $this->send($this->request('GET', '/rotation/connect/lastfm', ['authenticatedAs' => 2]));

        $this->assertStringContainsString('rotation=failed', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function a_guest_cannot_start(): void
    {
        $response = $this->send($this->request('GET', '/rotation/connect/lastfm'));

        $this->assertNotSame(302, $response->getStatusCode());
        FakeProvider::reset();
    }
}
