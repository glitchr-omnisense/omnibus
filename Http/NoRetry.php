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
 * Since omnisense/contracts 1568f2c this is the kernel's
 * Omnisense\Contracts\Http\NoRetry::of(), kept here under its public name.
 * With an older kernel (a site's vendor), the former code below runs:
 * withOptions(['max_retries' => 0]) reaches the RetryableHttpClient through
 * the decorators (TraceableHttpClient, ScopingHttpClient...) and turns it
 * off; a client with no retry refuses the option (an unsupported option)
 * and is kept as given. A MockHttpClient, which retries nothing, is kept as
 * given too: it would take the option and give a copy whose
 * getRequestsCount() a test no longer sees.
 */
final class NoRetry
{
    public static function of(HttpClientInterface $http): HttpClientInterface
    {
        if (class_exists(KernelNoRetry::class)) {
            return KernelNoRetry::of($http);
        }
        if (is_a($http, 'Symfony\\Component\\HttpClient\\MockHttpClient')) {
            return $http;
        }
        try {
            return $http->withOptions(['max_retries' => 0]);
        } catch (\InvalidArgumentException) {
            return $http;
        }
    }
}
