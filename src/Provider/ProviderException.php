<?php

namespace Ernestdefoe\Rotation\Provider;

/**
 * Why a request failed, in the terms the poller acts on. The message is a
 * short code, never a response body: nothing a service sends ends up in logs.
 */
class ProviderException extends \RuntimeException
{
    /** Try again later, backing off. */
    public const TRANSIENT = 'transient';
    /** The member hid their listening on the service. */
    public const PRIVATE = 'private';
    /** The account no longer exists. */
    public const MISSING = 'missing';
    /** The service asked us to slow down: leave it alone for a while. */
    public const RATE_LIMITED = 'rate_limited';
    /** The forum's key was refused: nothing will work until the admin fixes it. */
    public const BAD_KEY = 'bad_key';
    /** The member's approval was refused or expired. */
    public const DENIED = 'denied';
    /** This forum's request budget is spent for now. */
    public const BUDGET = 'budget';

    public function __construct(public string $kind, string $detail = '')
    {
        parent::__construct($detail === '' ? $kind : "$kind: $detail");
    }
}
