<?php

namespace Ernestdefoe\Rotation;

use Flarum\User\User;

/**
 * The one privacy rule, used on every path that shows a member's listening:
 * everyone, signed-in members only, or nobody but the member themself.
 */
class Access
{
    public static function canSee(User $actor, int $ownerId, string $visibility): bool
    {
        return match ($visibility) {
            'everyone' => true,
            'members' => ! $actor->isGuest(),
            default => $actor->id === $ownerId,
        };
    }

    /** The visibilities this viewer may see in lists of other members. @return list<string> */
    public static function scope(User $actor): array
    {
        return $actor->isGuest() ? ['everyone'] : ['everyone', 'members'];
    }
}
