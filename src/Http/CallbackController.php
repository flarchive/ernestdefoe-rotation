<?php

namespace Ernestdefoe\Rotation\Http;

use Ernestdefoe\Rotation\Accounts;
use Ernestdefoe\Rotation\Provider\ProviderException;
use Ernestdefoe\Rotation\Provider\Providers;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /rotation/callback/{provider}/{state} — back from the service.
 *
 * The state must be this member's, unused, unexpired and for this service.
 * Only then is the service asked which account approved us, and that is the
 * account linked: never a name taken from the request.
 */
class CallbackController implements RequestHandlerInterface
{
    public function __construct(
        private Providers $providers,
        private Accounts $accounts,
        private UrlGenerator $url,
        private SlugManager $slugs,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('rotation.connect');

        $session = $request->getAttribute('session');
        $saved = $session->get(ConnectController::STATE);
        // Single use: whatever happens next, this state is spent.
        $session->forget(ConnectController::STATE);

        $key = (string) Arr::get($request->getAttributes(), 'routeParameters.provider');
        $query = $request->getQueryParams();

        $valid = is_array($saved)
            && ($saved['expires'] ?? 0) >= time()
            && ($saved['user'] ?? null) === $actor->id
            && ($saved['provider'] ?? null) === $key
            && hash_equals((string) ($saved['state'] ?? ''), (string) Arr::get($request->getAttributes(), 'routeParameters.state'));

        $provider = $this->providers->get($key);
        if (! $valid || ! $provider) {
            return $this->back($actor, 'expired');
        }

        try {
            $name = $provider->complete($query);
        } catch (ProviderException $e) {
            return $this->back($actor, $e->kind === ProviderException::DENIED ? 'denied' : 'failed');
        }

        if (! $this->accounts->connect($actor->id, $key, $name)) {
            return $this->back($actor, 'taken');
        }

        return $this->back($actor, 'connected');
    }

    private function back(User $actor, string $result): ResponseInterface
    {
        $slug = $this->slugs->forResource(User::class)->toSlug($actor);

        return new RedirectResponse($this->url->to('forum')->route('rotation.user', ['username' => $slug]).'?rotation='.$result);
    }
}
