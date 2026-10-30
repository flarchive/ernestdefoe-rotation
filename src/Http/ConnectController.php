<?php

namespace Ernestdefoe\Rotation\Http;

use Ernestdefoe\Rotation\Provider\Providers;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /rotation/connect/{provider} — off to the service to approve the forum.
 *
 * 🚨 A one-time `state`, kept in this member's session for ten minutes, rides
 * along in the callback address. The callback refuses anything without it,
 * so nobody can trick a member into linking someone else's account.
 */
class ConnectController implements RequestHandlerInterface
{
    public const STATE = 'rotation.connect';

    public function __construct(private Providers $providers, private UrlGenerator $url, private Cache $cache, private SlugManager $slugs)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('rotation.connect');

        $key = (string) Arr::get($request->getAttributes(), 'routeParameters.provider');
        $provider = $this->providers->get($key);
        if (! $provider || ! $provider->configured()) {
            return new RedirectResponse($this->url->to('forum')->base());
        }

        // Each try ends in a Last.fm request: ten an hour per member is plenty.
        $tries = "rotation.connect.{$actor->id}";
        $this->cache->add($tries, 0, 3600);
        if ((int) $this->cache->increment($tries) > 10) {
            return new RedirectResponse($this->url->to('forum')->route('rotation.user', ['username' => $this->slugs->forResource(User::class)->toSlug($actor)]).'?rotation=failed');
        }

        $state = Str::random(40);
        $request->getAttribute('session')->put(self::STATE, ['state' => $state, 'provider' => $key, 'user' => $actor->id, 'expires' => time() + 600]);

        // In the path, not the query: Last.fm adds its own `?token=` to whatever address it is given.
        $callback = $this->url->to('forum')->route('rotation.callback', ['provider' => $key, 'state' => $state]);

        return new RedirectResponse($provider->authorizeUrl($callback));
    }
}
