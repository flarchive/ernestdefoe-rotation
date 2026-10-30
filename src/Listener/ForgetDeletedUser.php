<?php

namespace Ernestdefoe\Rotation\Listener;

use Ernestdefoe\Rotation\Accounts;
use Flarum\User\Event\Deleting;

/**
 * A deleted member's rows go with the user through foreign keys; this removes
 * them first so the charts can be rebuilt without their plays.
 */
class ForgetDeletedUser
{
    public function __construct(private Accounts $accounts)
    {
    }

    public function handle(Deleting $event): void
    {
        $this->accounts->purge($event->user->id, true);
    }
}
