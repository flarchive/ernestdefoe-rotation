<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * The rollups every total, level and leaderboard is read from, so their cost
 * never grows with the number of members, and they outlive raw plays.
 *
 * rotation_days:  per member per day — plays and capped listening time.
 * rotation_chart: per day per song or artist — plays by members who chose to
 *                 be on the leaderboards. Counts, not people.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('rotation_days', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->date('day');
            $table->unsignedInteger('plays')->default(0);
            $table->unsignedInteger('ms')->default(0);

            $table->primary(['user_id', 'day']);
            $table->index('day');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $schema->create('rotation_chart', function (Blueprint $table) {
            $table->date('day');
            // t = track, a = artist
            $table->char('kind', 1);
            $table->unsignedInteger('entity_id');
            $table->unsignedInteger('plays');
            $table->unsignedInteger('listeners');

            $table->primary(['day', 'kind', 'entity_id']);
            $table->index(['kind', 'day']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('rotation_chart');
        $schema->dropIfExists('rotation_days');
    },
];
