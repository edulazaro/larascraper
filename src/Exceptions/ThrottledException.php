<?php

namespace EduLazaro\Larascraper\Exceptions;

use RuntimeException;

/**
 * The request was not made because waiting its turn would have taken too long.
 *
 * Thrown by Throttle::pace() when the next free slot for a target is further away
 * than the key's `max_wait`, and never at all when `max_wait` is left at 0. It is
 * a REFUSAL TO ASK, not an answer: a caller that turns it into an empty result
 * would be reporting a queue as a fact about the site.
 */
class ThrottledException extends RuntimeException
{
    /**
     * @param int $seconds Seconds until this target is free again.
     */
    public function __construct(public readonly int $seconds, string $message = '')
    {
        parent::__construct($message ?: "Throttled: the target is busy for another {$seconds}s.");
    }
}
