<?php

namespace Omnibus\Http;

use Omnisense\Contracts\Http\NoRetry as KernelNoRetry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HTTP client a carrier's Api sends with, its retries off. Symfony's
 * RetryableHttpClient (framework.http_client's retry_failed, the
 * application's http_client service, which the bundle hands to every
 * factory) retries a 429, 423, 425, 502 and 503 whatever the method: a
 * label bought, a pickup booked or a shipment cancelled would be sent
 * again, and a 429 would never reach the carrier's wait.
 *
 * It is the kernel's Omnisense\Contracts\Http\NoRetry::of(), kept here
 * under its public name: a client with no retry, a MockHttpClient included,
 * is kept as given.
 */
final class NoRetry
{
    public static function of(HttpClientInterface $http): HttpClientInterface
    {
        return KernelNoRetry::of($http);
    }
}
