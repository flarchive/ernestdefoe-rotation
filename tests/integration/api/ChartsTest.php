<?php

namespace Ernestdefoe\Rotation\Tests\integration\api;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Rollups;
use Ernestdefoe\Rotation\Tests\integration\RotationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChartsTest extends RotationTestCase
{
    private function seedPlays(int $userId, int $times, string $title = 'Song'): void
    {
        $db = $this->db();
        $artist = $db->table('rotation_artists')->where('norm', sha1('artist'))->value('id')
            ?? $db->table('rotation_artists')->insertGetId(['name' => 'Artist', 'norm' => sha1('artist')]);
        $track = $db->table('rotation_tracks')->where('norm', sha1($title))->value('id')
            ?? $db->table('rotation_tracks')->insertGetId(['artist_id' => $artist, 'title' => $title, 'norm' => sha1($title)]);
        $at = CarbonImmutable::now('UTC')->startOfDay()->addMinutes(1);
        for ($i = 0; $i < $times; $i++) {
            $db->table('rotation_plays')->insert(['user_id' => $userId, 'track_id' => $track, 'artist_id' => $artist, 'played_at' => $at->addMinutes($i * 4)->toDateTimeString()]);
        }
        $this->app()->getContainer()->make(Rollups::class)->refreshUser($userId, [$at->toDateString()]);
    }

    private function charts(string $kinds, ?int $as = null): array
    {
        $response = $this->send($this->request('GET', '/api/rotation/charts', $as ? ['authenticatedAs' => $as] : [])->withQueryParams(['kinds' => $kinds, 'period' => 'week', 'limit' => 10]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    #[Test]
    public function only_members_who_opted_in_are_counted(): void
    {
        $this->connect(2, 'normal', ['leaderboards' => true]);
        $this->connect(3, 'third', ['leaderboards' => false]);
        $this->seedPlays(2, 3, 'Public song');
        $this->seedPlays(3, 5, 'Private song');
        $this->app()->getContainer()->make(Rollups::class)->rebuildCharts([CarbonImmutable::now('UTC')->toDateString()]);

        $data = $this->charts('tracks,listeners');

        $this->assertSame(['Public song'], array_column($data['tracks'], 'title'));
        $this->assertSame(3, $data['tracks'][0]['plays']);
        $this->assertSame([2], array_column(array_column($data['listeners'], 'user'), 'id'));
    }

    #[Test]
    public function one_member_on_repeat_counts_a_limited_number_of_times(): void
    {
        $this->connect(2, 'normal', ['leaderboards' => true]);
        $this->seedPlays(2, 40);
        $this->app()->getContainer()->make(Rollups::class)->rebuildCharts([CarbonImmutable::now('UTC')->toDateString()]);

        $this->assertSame(Rollups::CHART_REPEATS, $this->charts('tracks')['tracks'][0]['plays']);
    }

    #[Test]
    public function a_members_only_listener_is_hidden_from_guests(): void
    {
        $this->connect(2, 'normal', ['leaderboards' => true, 'visibility' => 'members']);
        $this->seedPlays(2, 2);

        $this->assertSame([], $this->charts('listeners')['listeners']);
        $this->assertCount(1, $this->charts('listeners', 3)['listeners']);
    }

    #[Test]
    public function all_time_reads_the_totals_not_the_last_week(): void
    {
        $this->connect(2, 'normal', ['leaderboards' => true, 'total_ms' => 7200000, 'total_plays' => 30]);

        $response = $this->send($this->request('GET', '/api/rotation/charts')->withQueryParams(['kinds' => 'listeners', 'period' => 'all']));
        $rows = json_decode((string) $response->getBody(), true)['data']['listeners'];

        $this->assertSame(7200000, $rows[0]['ms']);
    }

    #[Test]
    public function now_playing_follows_the_members_switch(): void
    {
        $live = ['now_playing' => json_encode(['title' => 'Live', 'artist' => 'A', 'url' => null]), 'now_playing_at' => CarbonImmutable::now('UTC')->toDateTimeString()];
        $this->connect(2, 'normal', $live);
        $this->connect(3, 'third', $live + ['show_now_playing' => false]);

        $this->assertSame([2], array_column(array_column($this->charts('now')['now'], 'user'), 'id'));
    }

    #[Test]
    public function the_boards_cost_the_same_queries_for_two_listeners_or_twenty(): void
    {
        $count = function (): int {
            $this->app()->getContainer()->make('cache.store')->flush();
            $queries = 0;
            $this->database()->listen(function () use (&$queries) {
                $queries++;
            });
            $this->charts('now,tracks,artists,listeners');
            $before = $queries;
            $this->app()->getContainer()->make('cache.store')->flush();
            $this->charts('now,tracks,artists,listeners');

            return $queries - $before;
        };

        $live = ['now_playing' => json_encode(['title' => 'T', 'artist' => 'A', 'url' => null]), 'now_playing_at' => CarbonImmutable::now('UTC')->toDateTimeString()];
        $this->connect(2, 'normal', ['leaderboards' => true] + $live);
        $this->connect(3, 'third', ['leaderboards' => true]);
        $this->seedPlays(2, 2);
        $this->seedPlays(3, 2);
        $this->app()->getContainer()->make(Rollups::class)->rebuildCharts([CarbonImmutable::now('UTC')->toDateString()]);
        $few = $count();

        for ($id = 10; $id < 28; $id++) {
            $this->db()->table('users')->insert(['id' => $id, 'username' => "u$id", 'email' => "u$id@machine.local", 'is_email_confirmed' => 1, 'password' => 'x', 'joined_at' => '2026-01-01 00:00:00']);
            $this->connect($id, "u$id", ['leaderboards' => true] + $live);
            $this->seedPlays($id, 2, "Song $id");
        }
        $this->app()->getContainer()->make(Rollups::class)->rebuildCharts([CarbonImmutable::now('UTC')->toDateString()]);

        $this->assertSame($few, $count());
    }
}
