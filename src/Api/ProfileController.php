<?php

namespace Ernestdefoe\Rotation\Api;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Access;
use Ernestdefoe\Rotation\Charts;
use Ernestdefoe\Rotation\Levels;
use Ernestdefoe\Rotation\Provider\Providers;
use Ernestdefoe\Rotation\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/rotation/users/{id} — a member's music tab.
 *
 * Three reads whatever is on it: the member, their profile row with their
 * account, and this week's total. The tracks are a snapshot the poller wrote,
 * so nothing here ever calls Last.fm.
 */
class ProfileController implements RequestHandlerInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private Levels $levels,
        private Settings $settings,
        private Providers $providers,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = (int) Arr::get($request->getAttributes(), 'routeParameters.id');

        $user = User::query()->whereVisibleTo($actor)->find($id);
        if (! $user) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404);
        }

        $self = $actor->id === $user->id;
        $row = $this->db->table('rotation_profiles as p')
            ->leftJoin('rotation_accounts as a', 'a.user_id', '=', 'p.user_id')
            ->where('p.user_id', $user->id)
            ->first(['p.*', 'a.provider', 'a.external_name', 'a.status', 'a.last_polled_at']);

        $data = ['self' => $self, 'connected' => (bool) $row?->external_name];

        if ($self) {
            $data['configured'] = $this->settings->configured();
            $data['canConnect'] = $actor->hasPermission('rotation.connect');
            $data['prefs'] = $row ? [
                'visibility' => $row->visibility,
                'showNowPlaying' => (bool) $row->show_now_playing,
                'leaderboards' => (bool) $row->leaderboards,
            ] : null;
        }

        if (! $row || ! Access::canSee($actor, $user->id, (string) $row->visibility)) {
            return new JsonResponse(['data' => $data + ['visible' => false]]);
        }

        $week = $this->db->table('rotation_days')->where('user_id', $user->id)
            ->where('day', '>=', CarbonImmutable::now('UTC')->startOfDay()->subDays(6)->toDateString())
            ->selectRaw('COALESCE(SUM(ms), 0) as ms, COALESCE(SUM(plays), 0) as plays')->first();

        $total = (int) $row->total_ms;
        $band = $this->levels->bandFor($total);
        $next = $this->levels->nextBand($total);
        $live = $row->now_playing_at && CarbonImmutable::parse($row->now_playing_at, 'UTC')->gte(CarbonImmutable::now('UTC')->subMinutes(Charts::LIVE_MINUTES));
        $provider = $row->provider ? $this->providers->get((string) $row->provider) : null;

        return new JsonResponse(['data' => $data + [
            'visible' => true,
            'account' => $row->external_name ? [
                'provider' => $row->provider,
                'name' => $row->external_name,
                'url' => $provider?->profileUrl((string) $row->external_name),
                'status' => $row->status,
                'pending' => $row->last_polled_at === null,
            ] : null,
            'nowPlaying' => $live && $row->show_now_playing ? json_decode((string) $row->now_playing, true) : null,
            'recent' => json_decode((string) $row->recent, true) ?: [],
            'tops' => json_decode((string) $row->tops, true) ?: null,
            'stats' => [
                'totalMs' => $total,
                'totalPlays' => (int) $row->total_plays,
                'weekMs' => (int) $week->ms,
                'weekPlays' => (int) $week->plays,
                'level' => $band ? ['level' => $band['level'], 'name' => $band['name'], 'from' => $band['from'], 'to' => $band['to']] : null,
                'next' => $next ? ['level' => $next['level'], 'name' => $next['name'], 'from' => $next['from']] : null,
            ],
        ]]);
    }
}
