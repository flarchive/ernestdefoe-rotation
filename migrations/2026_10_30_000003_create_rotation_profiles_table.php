<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A member's choices and the snapshot their music tab is drawn from, in one
 * row: the profile is one indexed read, never a query per track.
 */
return Migration::createTable('rotation_profiles', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    // everyone | members | me
    $table->string('visibility', 10)->default('everyone');
    $table->boolean('show_now_playing')->default(true);
    $table->boolean('leaderboards')->default(false);
    $table->text('now_playing')->nullable();
    $table->dateTime('now_playing_at')->nullable();
    $table->mediumText('recent')->nullable();
    $table->mediumText('tops')->nullable();
    $table->unsignedBigInteger('total_ms')->default(0);
    $table->unsignedInteger('total_plays')->default(0);
    $table->unsignedSmallInteger('level')->nullable();
    $table->dateTime('updated_at')->nullable();

    $table->index('now_playing_at');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
