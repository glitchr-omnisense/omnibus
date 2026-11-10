<?php

namespace Omnibus\Exception;

/**
 * The carrier answered 429 Too Many Requests: it refused the call, a
 * CarrierException as every refusal, with the seconds to wait when the
 * carrier said ($retryAfter, from Retry-After or RateLimit). The carrier's
 * next calls wait as long (UsageLimitReachedException before they are
 * sent). Nothing retries it: a label is never bought twice, a pickup never
 * booked twice by Omnibus.
 */
final class RateLimitedException extends CarrierException
{
    public function __construct(
        string $carrier,
        string $message,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($carrier, $message, 'rate_limited', $previous);
    }
}
