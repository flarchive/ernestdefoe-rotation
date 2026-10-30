<?php

namespace Ernestdefoe\Rotation\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "Your week in music": time, plays, top artist and top song. Sent to the
 * member about themself, so its subject is their own user and nothing else
 * needs a resource. Members choose alert and email in their notification
 * settings like any other notification: that is the opt-in.
 */
class RecapBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    /** @param array{week: string, ms: int, plays: int, artist: ?string, track: ?string, trackArtist: ?string} $recap */
    public function __construct(public User $user, public array $recap)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->user;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return $this->recap;
    }

    public static function getType(): string
    {
        return 'rotationRecap';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-rotation::emails.plain.recap',
            'html' => 'ernestdefoe-rotation::emails.html.recap',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-rotation.email.recap.subject');
    }

    /** The member's music tab, by the forum's own user slugs. */
    public function profileUrl(): string
    {
        $slug = resolve(SlugManager::class)->forResource(User::class)->toSlug($this->user);

        return resolve(UrlGenerator::class)->to('forum')->route('rotation.user', ['username' => $slug]);
    }

    /** "12 h 30 min" */
    public function duration(): string
    {
        $minutes = intdiv($this->recap['ms'], 60000);

        return intdiv($minutes, 60).' h '.($minutes % 60).' min';
    }
}
