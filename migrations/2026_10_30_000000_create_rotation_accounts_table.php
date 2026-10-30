<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One row per member per listening service. Only the verified username is
 * kept: Last.fm's web auth proves the member owns it, and reading their plays
 * needs no token, so no secret is ever stored.
 */
return Migration::createTable('rotation_accounts', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('provider', 20);
    $table->string('external_name', 100);
    // ok | private (hidden on the service) | missing (account gone) | paused (too many failures)
    $table->string('status', 10)->default('ok');
    $table->unsignedSmallInteger('fail_count')->default(0);
    $table->dateTime('next_poll_at')->nullable();
    $table->dateTime('last_polled_at')->nullable();
    // The newest play already stored: the next poll asks only for later ones.
    $table->dateTime('last_played_at')->nullable();
    $table->dateTime('tops_at')->nullable();
    $table->string('last_error', 100)->nullable();
    $table->dateTime('created_at');

    $table->unique(['user_id', 'provider']);
    // One forum account per Last.fm account, or two people's charts count it twice.
    $table->unique(['provider', 'external_name']);
    $table->index('next_poll_at');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
