<?php

namespace Ernestdefoe\Rotation\Tests\integration\api;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Provider\ProviderException;
use Ernestdefoe\Rotation\Tests\integration\FakeProvider;
use Ernestdefoe\Rotation\Tests\integration\RotationTestCase;
use PHPUnit\Framework\Attributes\Test;

class PollerTest extends RotationTestCase
{
    #[Test]
    public function plays_are_stored_once_however_often_they_are_seen(): void
    {
        $this->connect(2, 'normal');
        $now = CarbonImmutable::now('UTC')->startOfMinute();
        FakeProvider::$plays = [
            $this->play('Radiohead', 'Reckoner', $now->subMinutes(10)),
            $this->play('radiohead', 'Nude', $now->subMinutes(5)),
        ];

        $this->assertSame(2, $this->poll(2));
        $this->assertSame(0, $this->poll(2));
        $this->assertSame(2, $this->db()->table('rotation_plays')->where('user_id', 2)->count());
        // "Radiohead" and "radiohead" are one artist.
        $this->assertSame(1, $this->db()->table('rotation_artists')->count());
    }

    #[Test]
    public function future_and_stale_plays_are_ignored(): void
    {
        $this->connect(2, 'normal');
        $now = CarbonImmutable::now('UTC');
        FakeProvider::$plays = [
            $this->play('A', 'Future', $now->addHour()),
            $this->play('A', 'Ancient', $now->subDays(20)),
            $this->play('A', 'Fine', $now->subHour()),
        ];

        $this->assertSame(1, $this->poll(2));
    }

    #[Test]
    public function listening_time_uses_the_average_and_the_daily_cap(): void
    {
        $this->setting('ernestdefoe-rotation.daily_cap_hours', 1);
        $this->connect(2, 'normal');
        $day = CarbonImmutable::now('UTC')->startOfDay()->subDay()->addHours(2);
        $plays = [];
        for ($i = 0; $i < 30; $i++) {
            $plays[] = $this->play('Artist', "Song $i", $day->addMinutes($i * 4));
        }
        FakeProvider::$plays = $plays;

        $this->poll(2);

        // 30 × 3.5 min = 105 min, capped at one hour.
        $this->assertSame(3600000, (int) $this->db()->table('rotation_days')->where('user_id', 2)->value('ms'));
        $profile = $this->db()->table('rotation_profiles')->where('user_id', 2)->first();
        $this->assertSame(3600000, (int) $profile->total_ms);
        $this->assertSame(30, (int) $profile->total_plays);
        $this->assertSame(1, (int) $profile->level);
        $this->assertSame(1, (int) $this->db()->table('users')->where('id', 2)->value('rotation_level'));
    }

    #[Test]
    public function a_known_track_length_replaces_the_average(): void
    {
        $this->connect(2, 'normal');
        FakeProvider::$plays = [$this->play('A', 'Long', CarbonImmutable::now('UTC')->subMinutes(30))];
        $this->poll(2);

        $this->db()->table('rotation_tracks')->update(['duration_ms' => 600000, 'duration_source' => 'lastfm']);
        $this->app()->getContainer()->make(\Ernestdefoe\Rotation\Rollups::class)
            ->refreshUser(2, [CarbonImmutable::now('UTC')->subMinutes(30)->toDateString()]);

        $this->assertSame(600000, (int) $this->db()->table('rotation_profiles')->where('user_id', 2)->value('total_ms'));
    }

    #[Test]
    public function a_hidden_profile_shows_no_level_beside_posts(): void
    {
        $this->connect(2, 'normal', ['visibility' => 'members']);
        FakeProvider::$plays = [$this->play('A', 'B', CarbonImmutable::now('UTC')->subMinutes(30))];
        $this->poll(2);

        $this->assertSame(1, (int) $this->db()->table('rotation_profiles')->where('user_id', 2)->value('level'));
        $this->assertNull($this->db()->table('users')->where('id', 2)->value('rotation_level'));
    }

    #[Test]
    public function now_playing_is_kept_and_polled_again_soon(): void
    {
        $this->connect(2, 'normal');
        FakeProvider::$nowPlaying = ['artist' => 'A', 'title' => 'Now', 'url' => null];
        $this->poll(2);

        $profile = $this->db()->table('rotation_profiles')->where('user_id', 2)->first();
        $this->assertSame('Now', json_decode($profile->now_playing, true)['title']);
        $next = CarbonImmutable::parse($this->db()->table('rotation_accounts')->where('user_id', 2)->value('next_poll_at'), 'UTC');
        $this->assertTrue($next->lte(CarbonImmutable::now('UTC')->addMinutes(4)));
    }

    #[Test]
    public function a_new_connection_with_nothing_recent_is_checked_again_soon(): void
    {
        $this->connect(2, 'normal');
        $this->poll(2);

        $next = CarbonImmutable::parse($this->db()->table('rotation_accounts')->where('user_id', 2)->value('next_poll_at'), 'UTC');
        $this->assertTrue($next->lte(CarbonImmutable::now('UTC')->addMinutes(16)));
    }

    #[Test]
    public function a_hidden_lastfm_profile_is_marked_and_checked_daily(): void
    {
        $this->connect(2, 'normal');
        FakeProvider::$fail = ProviderException::PRIVATE;

        $poller = $this->app()->getContainer()->make(\Ernestdefoe\Rotation\Poller::class);
        $poller->run(microtime(true) + 5);

        $account = $this->db()->table('rotation_accounts')->where('user_id', 2)->first();
        $this->assertSame('private', $account->status);
        $this->assertTrue(CarbonImmutable::parse($account->next_poll_at, 'UTC')->gt(CarbonImmutable::now('UTC')->addHours(23)));
    }

    #[Test]
    public function the_run_stops_at_the_global_cap(): void
    {
        $this->setting('ernestdefoe-rotation.global_cap', 10);
        $this->connect(2, 'normal');
        $this->connect(3, 'third');
        // Use up this window's allowance.
        $budget = $this->app()->getContainer()->make(\Ernestdefoe\Rotation\Budget::class);
        for ($i = 0; $i < 10; $i++) {
            $budget->take('test', 1000);
        }

        $this->assertFalse($budget->take('lastfm', 1000));
    }
}
