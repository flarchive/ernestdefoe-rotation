<?php

namespace Ernestdefoe\Rotation\Tests\integration\api;

use Ernestdefoe\Rotation\Tests\integration\RotationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ProfileTest extends RotationTestCase
{
    private function profile(int $userId, ?int $as): array
    {
        $response = $this->send($this->request('GET', "/api/rotation/users/$userId", $as ? ['authenticatedAs' => $as] : []));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    public static function visibility(): array
    {
        // visibility, viewer (null = guest), sees it
        return [
            ['everyone', null, true],
            ['members', null, false],
            ['members', 3, true],
            ['me', 3, false],
            ['me', 1, false],
            ['me', 2, true],
        ];
    }

    #[Test]
    #[DataProvider('visibility')]
    public function each_member_decides_who_sees_their_music(string $visibility, ?int $viewer, bool $sees): void
    {
        $this->connect(2, 'normal', ['visibility' => $visibility, 'recent' => json_encode([['title' => 'Secret song', 'artist' => 'X', 'url' => null, 'playedAt' => '2026-10-30T10:00:00+00:00']])]);

        $data = $this->profile(2, $viewer);

        $this->assertSame($sees, $data['visible']);
        $this->assertSame($sees, str_contains(json_encode($data), 'Secret song'));
    }

    #[Test]
    public function only_the_member_gets_their_choices(): void
    {
        $this->connect(2, 'normal', ['visibility' => 'everyone']);

        $this->assertArrayHasKey('prefs', $this->profile(2, 2));
        $this->assertArrayNotHasKey('prefs', $this->profile(2, 3));
        $this->assertArrayNotHasKey('prefs', $this->profile(2, null));
    }

    #[Test]
    public function choices_are_saved_and_a_hidden_profile_drops_its_post_level(): void
    {
        $this->connect(2, 'normal', ['visibility' => 'everyone', 'level' => 3]);
        $this->db()->table('users')->where('id', 2)->update(['rotation_level' => 3]);

        $response = $this->send($this->request('PATCH', '/api/rotation/me', ['authenticatedAs' => 2, 'json' => ['data' => ['visibility' => 'me', 'showNowPlaying' => false, 'leaderboards' => true]]]));
        $this->assertSame(204, $response->getStatusCode());

        $profile = $this->db()->table('rotation_profiles')->where('user_id', 2)->first();
        $this->assertSame('me', $profile->visibility);
        $this->assertFalse((bool) $profile->show_now_playing);
        $this->assertTrue((bool) $profile->leaderboards);
        $this->assertNull($this->db()->table('users')->where('id', 2)->value('rotation_level'));
    }

    #[Test]
    public function disconnecting_removes_everything_personal(): void
    {
        $this->connect(2, 'normal');
        $this->db()->table('rotation_artists')->insert(['id' => 1, 'name' => 'A', 'norm' => sha1('a')]);
        $this->db()->table('rotation_tracks')->insert(['id' => 1, 'artist_id' => 1, 'title' => 'T', 'norm' => sha1('t')]);
        $this->db()->table('rotation_plays')->insert(['user_id' => 2, 'track_id' => 1, 'artist_id' => 1, 'played_at' => '2026-10-29 10:00:00']);
        $this->db()->table('rotation_days')->insert(['user_id' => 2, 'day' => '2026-10-29', 'plays' => 1, 'ms' => 1000]);

        $response = $this->send($this->request('DELETE', '/api/rotation/me', ['authenticatedAs' => 2]));
        $this->assertSame(204, $response->getStatusCode());

        foreach (['rotation_accounts', 'rotation_plays', 'rotation_days', 'rotation_profiles'] as $table) {
            $this->assertSame(0, $this->db()->table($table)->where('user_id', 2)->count(), $table);
        }
    }

    #[Test]
    public function a_guest_cannot_change_anything(): void
    {
        $this->connect(2, 'normal');
        $request = $this->requestWithCsrfToken($this->request('PATCH', '/api/rotation/me', ['json' => ['data' => ['visibility' => 'me']]]));
        $response = $this->send($request);

        $this->assertContains($response->getStatusCode(), [400, 401, 403]);
        $this->assertSame('everyone', $this->db()->table('rotation_profiles')->where('user_id', 2)->value('visibility'));
    }
}
