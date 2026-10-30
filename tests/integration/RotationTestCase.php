<?php

namespace Ernestdefoe\Rotation\Tests\integration;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Poller;
use Ernestdefoe\Rotation\Provider\Providers;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

abstract class RotationTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        FakeProvider::reset();
        Providers::$classes['lastfm'] = FakeProvider::class;

        $this->extension('ernestdefoe-rotation');
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'third', 'email' => 'third@machine.local', 'is_email_confirmed' => 1, 'last_seen_at' => CarbonImmutable::now()->toDateTimeString()],
            ],
        ]);
        $this->setting('ernestdefoe-rotation.lastfm_key', 'k');
        $this->setting('ernestdefoe-rotation.lastfm_secret', 's');
    }

    protected function db(): ConnectionInterface
    {
        return $this->app()->getContainer()->make(ConnectionInterface::class);
    }

    /** Link an account the way the callback does, with the given choices. */
    protected function connect(int $userId, string $name, array $profile = []): void
    {
        $now = CarbonImmutable::now('UTC')->toDateTimeString();
        $this->db()->table('rotation_accounts')->insert([
            'user_id' => $userId, 'provider' => 'lastfm', 'external_name' => $name,
            'status' => 'ok', 'next_poll_at' => $now, 'created_at' => $now,
        ]);
        $this->db()->table('rotation_profiles')->insert($profile + ['user_id' => $userId, 'visibility' => 'everyone']);
    }

    protected function poll(int $userId): int
    {
        $account = $this->db()->table('rotation_accounts')->where('user_id', $userId)->first();
        $poller = $this->app()->getContainer()->make(Poller::class);

        return $poller->poll(new FakeProvider(), $account);
    }

    protected function play(string $artist, string $title, CarbonImmutable $at): array
    {
        return ['artist' => $artist, 'title' => $title, 'playedAt' => $at];
    }
}
