<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Raw plays, kept for the admin's retention period. The unique key makes
 * overlapping polls harmless: a play already stored is simply skipped.
 */
return Migration::createTable('rotation_plays', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('track_id');
    $table->unsignedInteger('artist_id');
    $table->dateTime('played_at');

    $table->unique(['user_id', 'track_id', 'played_at']);
    $table->index(['user_id', 'played_at']);
    $table->index('played_at');
    $table->index('track_id');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('track_id')->references('id')->on('rotation_tracks')->cascadeOnDelete();
});
