<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'rotation.connect' => Group::MEMBER_ID,
    'rotation.viewLeaderboards' => Group::GUEST_ID,
]);
