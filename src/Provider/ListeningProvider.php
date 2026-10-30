<?php

namespace Ernestdefoe\Rotation\Provider;

use Carbon\CarbonImmutable;

/**
 * A listening service. Last.fm is the only one today; another (ListenBrainz,
 * say) implements this and is added to Providers, and nothing else changes.
 */
interface ListeningProvider
{
    public function key(): string;

    /** Whether the forum's admin has set this service up. */
    public function configured(): bool;

    /** Where the member goes to approve the forum; they come back to $callback. */
    public function authorizeUrl(string $callback): string;

    /**
     * The member is back from the service: prove which account they own.
     *
     * @param array<string, mixed> $query the callback's query string
     * @return string the verified account name
     *
     * @throws ProviderException
     */
    public function complete(array $query): string;

    /**
     * Plays after $since (or the last 14 days on a first poll), what is
     * playing now, and the latest few tracks for the profile.
     *
     * @throws ProviderException
     */
    public function recent(string $account, ?CarbonImmutable $since): Listening;

    /**
     * Top tracks and artists over the last month and all time.
     *
     * @return array{month: array{tracks: list<array>, artists: list<array>}, overall: array{tracks: list<array>, artists: list<array>}}
     *
     * @throws ProviderException
     */
    public function tops(string $account): array;

    /**
     * A track's length in milliseconds, if the service knows it.
     *
     * @throws ProviderException
     */
    public function trackLength(string $artist, string $title, ?string $mbid): ?int;

    /** The account's page on the service. */
    public function profileUrl(string $account): string;
}
