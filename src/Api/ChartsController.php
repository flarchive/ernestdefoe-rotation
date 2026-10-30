<?php

namespace Ernestdefoe\Rotation\Api;

use Ernestdefoe\Rotation\Charts;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/rotation/charts?kinds=now,tracks,artists,listeners&period=week&limit=10
 *
 * Every board the page needs in one request: the leaderboard page asks for
 * one, a page of widgets asks once for all of them.
 */
class ChartsController implements RequestHandlerInterface
{
    private const KINDS = ['now', 'tracks', 'artists', 'listeners'];
    private const LIMITS = [5, 10, 25, 50];

    public function __construct(private Charts $charts)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $query = $request->getQueryParams();

        $kinds = array_intersect(self::KINDS, explode(',', (string) ($query['kinds'] ?? 'tracks')));
        $period = Charts::period($query['period'] ?? null);
        $limit = (int) ($query['limit'] ?? 10);
        $limit = in_array($limit, self::LIMITS, true) ? $limit : 10;

        // "Now playing" is a member's own choice to share; the boards are behind a permission.
        $boards = $actor->hasPermission('rotation.viewLeaderboards');
        $data = [];

        foreach ($kinds as $kind) {
            $data[$kind] = match ($kind) {
                'now' => $this->charts->nowPlaying($actor, $limit),
                'tracks' => $boards ? $this->charts->entities('t', $period, $limit) : [],
                'artists' => $boards ? $this->charts->entities('a', $period, $limit) : [],
                'listeners' => $boards ? $this->charts->listeners($actor, $period, $limit) : [],
            };
        }

        return new JsonResponse(['data' => $data, 'meta' => ['period' => $period]]);
    }
}
