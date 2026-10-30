<?php

namespace Ernestdefoe\Rotation\Job;

use Ernestdefoe\Rotation\Embed\Bandcamp;
use Flarum\Queue\AbstractJob;

/** Look up player ids for the links in a post, off the request that saved it. */
class ResolveEmbeds extends AbstractJob
{
    /** @param list<string> $urls normalized Bandcamp links */
    public function __construct(public array $urls)
    {
        parent::__construct();
    }

    public function handle(Bandcamp $bandcamp): void
    {
        $bandcamp->resolve(array_slice($this->urls, 0, 10));
    }
}
