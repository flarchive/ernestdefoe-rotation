<?php

namespace Ernestdefoe\Rotation\Listener;

use Ernestdefoe\Rotation\Levels;
use Ernestdefoe\Rotation\Settings;
use Flarum\Settings\Event\Saved;

/** New level bands apply to every member at once, not at their next play. */
class Relevel
{
    public function __construct(private Levels $levels)
    {
    }

    public function handle(Saved $event): void
    {
        if (array_key_exists(Settings::PREFIX.'bands', $event->settings)) {
            $this->levels->relevelAll();
        }
    }
}
