<?php

/*
 * Rotation — members' music listening for Flarum.
 *
 * The shape of it: members link Last.fm (web auth proves the account is
 * theirs); a scheduled poller fetches their plays in the background, within a
 * strict request budget; plays roll up per member per day (listening time,
 * levels) and per song and artist per day (charts); every page, widget and
 * board reads only those rollups and a per-member snapshot, never Last.fm.
 * Music links in posts become players built in the browser from the link
 * itself, on an allow-list.
 */

use Ernestdefoe\Rotation\Api;
use Ernestdefoe\Rotation\Console;
use Ernestdefoe\Rotation\Gdpr\RotationData;
use Ernestdefoe\Rotation\Http;
use Ernestdefoe\Rotation\Listener;
use Ernestdefoe\Rotation\Notification\RecapBlueprint;
use Ernestdefoe\Rotation\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Settings\Event\Saved as SettingsSaved;
use Flarum\User\Event\Deleting as UserDeleting;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The music tab, the leaderboards and the settings panel are their own
        // chunks, fetched when first opened.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/rotation', 'rotation.charts')
        ->route('/u/{username}/music', 'rotation.user'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-rotation', __DIR__.'/views'),

    (new Extend\Routes('forum'))
        ->get('/rotation/connect/{provider:[a-z]+}', 'rotation.connect', Http\ConnectController::class)
        ->get('/rotation/callback/{provider:[a-z]+}/{state:[A-Za-z0-9]{40}}', 'rotation.callback', Http\CallbackController::class),

    (new Extend\Routes('api'))
        ->get('/rotation/users/{id:[0-9]+}', 'rotation.api.profile', Api\ProfileController::class)
        ->patch('/rotation/me', 'rotation.api.me.update', Api\MeController::class)
        ->delete('/rotation/me', 'rotation.api.me.disconnect', Api\MeController::class)
        ->get('/rotation/charts', 'rotation.api.charts', Api\ChartsController::class)
        ->get('/rotation/embeds', 'rotation.api.embeds', Api\EmbedsController::class),

    (new Extend\Settings())
        ->default(Settings::PREFIX.'average_minutes', 3.5)
        ->default(Settings::PREFIX.'daily_cap_hours', 16)
        ->default(Settings::PREFIX.'retention_days', 90)
        ->default(Settings::PREFIX.'rate', 1)
        ->default(Settings::PREFIX.'global_cap', 300)
        ->default(Settings::PREFIX.'default_visibility', 'everyone')
        ->default(Settings::PREFIX.'post_levels', true)
        ->default(Settings::PREFIX.'sidebar_now_playing', true)
        ->serializeToForum('rotationPostLevels', Settings::PREFIX.'post_levels', 'boolval')
        ->serializeToForum('rotationSidebar', Settings::PREFIX.'sidebar_now_playing', 'boolval')
        ->serializeToForum('rotationEmbeds', Settings::PREFIX.'embeds', fn ($v) => resolve(Settings::class)->embeds())
        ->serializeToForum('rotationLevelNames', Settings::PREFIX.'bands', function () {
            $names = [];
            foreach (resolve(Settings::class)->bands() as $band) {
                $names[$band['level']] ??= $band['name'];
            }

            return (object) $names;
        }),

    // Permissions the actor's groups were already loaded for: no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewRotationLeaderboards')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('rotation.viewLeaderboards')),
        ]),

    // A column on the user row itself: the level beside every post author costs nothing.
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            Schema\Integer::make('rotationLevel')
                ->nullable()
                ->get(fn ($user) => $user->rotation_level !== null ? (int) $user->rotation_level : null),
        ]),

    (new Extend\Notification())
        ->type(RecapBlueprint::class, ['alert']),

    (new Extend\Event())
        ->listen(Posted::class, Listener\QueueEmbeds::class)
        ->listen(Revised::class, Listener\QueueEmbeds::class)
        ->listen(SettingsSaved::class, Listener\Relevel::class)
        ->listen(UserDeleting::class, Listener\ForgetDeletedUser::class),

    (new Extend\Console())
        ->command(Console\PollCommand::class)
        ->command(Console\ChartsCommand::class)
        ->command(Console\PruneCommand::class)
        ->command(Console\RecapCommand::class)
        ->schedule(Console\PollCommand::class, function (Event $event): void {
            $event->everyMinute()->withoutOverlapping(10);
        })
        ->schedule(Console\ChartsCommand::class, function (Event $event): void {
            $event->hourly()->withoutOverlapping(30);
        })
        ->schedule(Console\PruneCommand::class, function (Event $event): void {
            $event->dailyAt('03:17');
        })
        ->schedule(Console\RecapCommand::class, function (Event $event): void {
            $event->weeklyOn(1, '08:00');
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())->addType(RotationData::class),
        ]),
];
