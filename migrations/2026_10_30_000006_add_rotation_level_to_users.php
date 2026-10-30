<?php

use Flarum\Database\Migration;

// The level shown beside a member's posts, read with the user row itself: no
// query per author. Set only while their profile is visible to everyone.
return Migration::addColumns('users', [
    'rotation_level' => ['smallInteger', 'unsigned' => true, 'nullable' => true],
]);
