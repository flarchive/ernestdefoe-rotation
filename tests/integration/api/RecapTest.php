<?php

namespace Ernestdefoe\Rotation\Tests\integration\api;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Console\RecapCommand;
use Ernestdefoe\Rotation\Tests\integration\RotationTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RecapTest extends RotationTestCase
{
    #[Test]
    public function members_who_listened_last_week_get_a_recap(): void
    {
        $this->connect(2, 'normal');
        $this->connect(3, 'third');
        $monday = CarbonImmutable::now('UTC')->startOfWeek()->subWeek();
        $this->db()->table('rotation_artists')->insert(['id' => 1, 'name' => 'Big Thief', 'norm' => sha1('big thief')]);
        $this->db()->table('rotation_tracks')->insert(['id' => 1, 'artist_id' => 1, 'title' => 'Vampire Empire', 'norm' => sha1('ve')]);
        $this->db()->table('rotation_plays')->insert(['user_id' => 2, 'track_id' => 1, 'artist_id' => 1, 'played_at' => $monday->addHours(10)->toDateTimeString()]);
        $this->db()->table('rotation_days')->insert(['user_id' => 2, 'day' => $monday->toDateString(), 'plays' => 1, 'ms' => 240000]);

        $this->app()->getContainer()->make(RecapCommand::class)->run(new ArrayInput([]), new BufferedOutput());

        $rows = $this->db()->table('notifications')->where('type', 'rotationRecap')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]->user_id);
        $this->assertSame('Big Thief', json_decode($rows[0]->data, true)['artist']);

        // The member's notification list still loads with it in.
        $response = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 2]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }
}
