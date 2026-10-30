<?php

namespace Ernestdefoe\Rotation\Provider;

use Illuminate\Contracts\Container\Container;

/** The listening services this forum can use, by key. */
class Providers
{
    /** @var array<string, class-string<ListeningProvider>> */
    public static array $classes = ['lastfm' => LastFm::class];

    public function __construct(private Container $container)
    {
    }

    public function get(string $key): ?ListeningProvider
    {
        $class = self::$classes[$key] ?? null;

        return $class ? $this->container->make($class) : null;
    }
}
