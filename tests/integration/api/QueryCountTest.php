<?php

namespace Ernestdefoe\Rotation\Tests\integration\api;

use Ernestdefoe\Rotation\Tests\integration\RotationTestCase;
use PHPUnit\Framework\Attributes\Test;

/** The level beside post authors and on user cards costs nothing per author. */
class QueryCountTest extends RotationTestCase
{
    private function queries(string $path, array $params, ?int $as): int
    {
        $count = 0;
        $request = $this->request('GET', $path, $as ? ['authenticatedAs' => $as] : [])->withQueryParams($params);
        $this->send($request);
        $this->database()->listen(function () use (&$count) {
            $count++;
        });
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode());

        return $count;
    }

    private function addUsers(int $from, int $to): void
    {
        for ($id = $from; $id < $to; $id++) {
            $this->db()->table('users')->insert(['id' => $id, 'username' => "u$id", 'email' => "u$id@machine.local", 'is_email_confirmed' => 1, 'password' => 'x', 'joined_at' => '2026-01-01 00:00:00', 'rotation_level' => $id % 5]);
        }
    }

    #[Test]
    public function users_cost_the_same_for_one_or_twenty(): void
    {
        foreach ([null, 1] as $as) {
            $this->db()->table('users')->where('id', '>=', 10)->delete();
            $this->addUsers(10, 11);
            $one = $this->queries('/api/users', ['page' => ['limit' => 20]], $as ?? 1);
            $this->addUsers(11, 30);
            $twenty = $this->queries('/api/users', ['page' => ['limit' => 20]], $as ?? 1);

            $this->assertSame($one, $twenty);
        }
    }

    #[Test]
    public function the_level_is_on_the_user(): void
    {
        $this->db()->table('users')->where('id', 2)->update(['rotation_level' => 4]);
        $response = $this->send($this->request('GET', '/api/users/2', ['authenticatedAs' => 1]));

        $this->assertSame(4, json_decode((string) $response->getBody(), true)['data']['attributes']['rotationLevel']);
    }
}
