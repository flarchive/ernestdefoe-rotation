<?php

namespace Ernestdefoe\Rotation\Console;

use Carbon\CarbonImmutable;
use Ernestdefoe\Rotation\Notification\RecapBlueprint;
use Flarum\Console\AbstractCommand;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Last week's listening, Monday to Sunday (UTC), for everyone who listened.
 * Scheduled Mondays. Members who switched the notification off get nothing:
 * the notification settings are the opt-in.
 */
class RecapCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db, private NotificationSyncer $notifications)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('rotation:recap')->setDescription("Send members their week in music.");
    }

    protected function fire(): int
    {
        $start = CarbonImmutable::now('UTC')->startOfWeek()->subWeek();
        $end = $start->addWeek();
        $sent = 0;

        $this->db->table('rotation_days')
            ->where('day', '>=', $start->toDateString())->where('day', '<', $end->toDateString())
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(ms) as ms, SUM(plays) as plays')
            ->orderBy('user_id')
            ->chunk(200, function ($rows) use ($start, $end, &$sent) {
                $ids = $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all();
                $artists = $this->tops($ids, 'artist_id', $start, $end);
                $tracks = $this->tops($ids, 'track_id', $start, $end);

                $artistNames = $this->db->table('rotation_artists')->whereIn('id', array_values($artists))->pluck('name', 'id');
                $trackRows = $this->db->table('rotation_tracks as t')->join('rotation_artists as a', 'a.id', '=', 't.artist_id')
                    ->whereIn('t.id', array_values($tracks))->get(['t.id', 't.title', 'a.name'])->keyBy('id');
                $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');

                foreach ($rows as $row) {
                    $user = $users[(int) $row->user_id] ?? null;
                    if (! $user || (int) $row->plays === 0) {
                        continue;
                    }
                    $track = $trackRows[$tracks[$user->id] ?? 0] ?? null;

                    $this->notifications->sync(new RecapBlueprint($user, [
                        'week' => $start->toDateString(),
                        'ms' => (int) $row->ms,
                        'plays' => (int) $row->plays,
                        'artist' => $artistNames[$artists[$user->id] ?? 0] ?? null,
                        'track' => $track?->title,
                        'trackArtist' => $track?->name,
                    ]), [$user]);
                    $sent++;
                }
            });

        $this->info("Sent $sent recaps.");

        return 0;
    }

    /**
     * Each member's most played artist or song in the week, in one query.
     *
     * @param list<int> $ids
     * @return array<int, int> entity id by user id
     */
    private function tops(array $ids, string $column, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $best = [];
        $rows = $this->db->table('rotation_plays')
            ->whereIn('user_id', $ids)
            ->where('played_at', '>=', $start->toDateTimeString())->where('played_at', '<', $end->toDateTimeString())
            ->groupBy('user_id', $column)
            ->selectRaw("user_id, $column as entity, COUNT(*) as n")
            ->get();

        foreach ($rows as $row) {
            $u = (int) $row->user_id;
            if (! isset($best[$u]) || $row->n > $best[$u][1]) {
                $best[$u] = [(int) $row->entity, (int) $row->n];
            }
        }

        return array_map(fn ($b) => $b[0], $best);
    }
}
