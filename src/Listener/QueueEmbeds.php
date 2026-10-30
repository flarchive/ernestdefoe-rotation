<?php

namespace Ernestdefoe\Rotation\Listener;

use Ernestdefoe\Rotation\Embed\Bandcamp;
use Ernestdefoe\Rotation\Job\ResolveEmbeds;
use Ernestdefoe\Rotation\Settings;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Illuminate\Contracts\Queue\Queue;

/** A new or edited post with Bandcamp links: look their players up in the queue. */
class QueueEmbeds
{
    public function __construct(private Settings $settings, private Queue $queue)
    {
    }

    public function handle(Posted|Revised $event): void
    {
        if (! in_array('bandcamp', $this->settings->embeds(), true)) {
            return;
        }

        $urls = Bandcamp::find((string) $event->post->getParsedContentAttribute());
        if ($urls) {
            $this->queue->push(new ResolveEmbeds($urls));
        }
    }
}
