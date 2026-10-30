<?php

namespace Ernestdefoe\Rotation\Api;

use Ernestdefoe\Rotation\Accounts;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PATCH  /api/rotation/me — the member's own choices.
 * DELETE /api/rotation/me — disconnect, and remove their listening data.
 */
class MeController implements RequestHandlerInterface
{
    public function __construct(private Accounts $accounts)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if ($request->getMethod() === 'DELETE') {
            $this->accounts->disconnect($actor->id);
        } else {
            $body = (array) $request->getParsedBody();
            $this->accounts->update($actor->id, (array) Arr::get($body, 'data', []));
        }

        return new EmptyResponse(204);
    }
}
