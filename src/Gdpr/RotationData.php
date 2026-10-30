<?php

namespace Ernestdefoe\Rotation\Gdpr;

use Ernestdefoe\Rotation\Accounts;
use Flarum\Gdpr\Data\Type;
use Illuminate\Database\ConnectionInterface;

/**
 * A member's listening data in their flarum/gdpr export, and removed with it:
 * the linked account (only its name — Rotation stores no tokens), their
 * choices and snapshot, daily totals and every kept play. Plays are personal,
 * so anonymising removes them too.
 */
class RotationData extends Type
{
    public function export(): ?array
    {
        $db = resolve(ConnectionInterface::class);
        $id = $this->user->id;
        $files = [];

        $account = $db->table('rotation_accounts')->where('user_id', $id)->first(['provider', 'external_name', 'status', 'created_at']);
        $profile = $db->table('rotation_profiles')->where('user_id', $id)->first();
        if ($account || $profile) {
            $files[] = ['rotation/account.json' => $this->encodeForExport(['account' => $account, 'profile' => $profile])];
        }

        $days = $db->table('rotation_days')->where('user_id', $id)->orderBy('day')->get(['day', 'plays', 'ms'])->all();
        if ($days) {
            $files[] = ['rotation/days.json' => $this->encodeForExport($days)];
        }

        $plays = [];
        $db->table('rotation_plays as p')
            ->join('rotation_tracks as t', 't.id', '=', 'p.track_id')
            ->join('rotation_artists as a', 'a.id', '=', 'p.artist_id')
            ->where('p.user_id', $id)
            ->orderBy('p.id')
            ->select(['p.id', 'p.played_at', 't.title', 'a.name as artist'])
            ->chunk(5000, function ($rows) use (&$plays) {
                foreach ($rows as $row) {
                    $plays[] = ['playedAt' => $row->played_at, 'artist' => $row->artist, 'title' => $row->title];
                }
            });
        if ($plays) {
            $files[] = ['rotation/plays.json' => $this->encodeForExport($plays)];
        }

        return $files;
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-rotation.lib.gdpr.export');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-rotation.lib.gdpr.delete');
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        resolve(Accounts::class)->purge($this->user->id, true);
    }
}
