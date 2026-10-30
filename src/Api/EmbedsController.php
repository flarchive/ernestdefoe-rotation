<?php

namespace Ernestdefoe\Rotation\Api;

use Ernestdefoe\Rotation\Embed\Bandcamp;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/rotation/embeds?urls[]=… — the player ids already looked up for
 * these Bandcamp links. Read-only: it never fetches anything, so it cannot be
 * used to make the forum request an address.
 */
class EmbedsController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $urls = (array) ($request->getQueryParams()['urls'] ?? []);
        $byHash = [];
        foreach (array_slice($urls, 0, 30) as $url) {
            if (is_string($url) && ($normalized = Bandcamp::normalize($url))) {
                $byHash[Bandcamp::hash($normalized)][] = $url;
            }
        }

        $data = [];
        if ($byHash) {
            $rows = $this->db->table('rotation_embeds')->whereIn('url_hash', array_keys($byHash))->whereNotNull('ref')->get();
            foreach ($rows as $row) {
                foreach ($byHash[$row->url_hash] as $url) {
                    $data[$url] = ['kind' => $row->kind, 'id' => $row->ref];
                }
            }
        }

        return new JsonResponse(['data' => (object) $data]);
    }
}
