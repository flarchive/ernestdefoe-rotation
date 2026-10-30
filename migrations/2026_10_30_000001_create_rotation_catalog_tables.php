<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Artists and tracks, one row per song however many members play it.
 * `norm` is a hash of the normalised names, so "The Beatles" and "the beatles"
 * are one artist. A track's length is what listening time is made of.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('rotation_artists', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 255);
            $table->char('norm', 40)->unique();
            $table->char('mbid', 36)->nullable();
        });

        $schema->create('rotation_tracks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('artist_id');
            $table->string('title', 255);
            $table->char('norm', 40)->unique();
            $table->char('mbid', 36)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // lastfm | musicbrainz | none (looked up, nothing found: the admin's average applies)
            $table->string('duration_source', 12)->nullable();
            $table->unsignedTinyInteger('duration_tries')->default(0);

            $table->index('duration_source');
            $table->foreign('artist_id')->references('id')->on('rotation_artists')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('rotation_tracks');
        $schema->dropIfExists('rotation_artists');
    },
];
