<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Player ids that cannot be read from a link itself (Bandcamp's numeric ids),
 * looked up once in the queue and cached here.
 */
return Migration::createTable('rotation_embeds', function (Blueprint $table) {
    $table->char('url_hash', 40)->primary();
    $table->string('kind', 10)->nullable();
    $table->string('ref', 32)->nullable();
    $table->dateTime('resolved_at');
});
