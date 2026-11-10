<?php

namespace Omnibus\Exception;

use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * A call refused before anything was sent to the carrier: a rate limit of
 * its usage policy is reached (as the site runs it), or the carrier asked to
 * wait and the wait is not over (a 429's Retry-After, a RateLimit field with
 * nothing left). Nothing was booked, nothing was bought: the same call may
 * be made again after $retryAfter seconds.
 */
final class UsageLimitReachedException extends UsageLimitReached implements OmnibusException
{
}
