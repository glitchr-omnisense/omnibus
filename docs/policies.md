---
title: Usage policies and rate limits
order: 55
---

# Usage policies and rate limits

Omnibus is built on [omnisense/contracts](https://github.com/glitchr-omnisense/contracts). Each
carrier package declares its **usage policy** as data: rate limits, rights, requirements, and
where they were read. The file is `policy.json` in the package, named by `extra.omnisense.policy`
in its `composer.json`, in the schema of omnisense/contracts
(`resources/usage-policy.schema.json`). Run `vendor/bin/omnisense-policies` to print every
installed one.

Most carriers give access under a contract with the shipper: `commercial` is `contract`, and a
limit nobody published is `null` (unknown), never a guess. `verifiedAt` is the day the terms were
read at their source; it stays `null` where they could not be read, and for the carriers whose
API calls are still unverified against a live account.

| Package | Rate limits declared | Read |
|---|---|---|
| omnibus/ups | per plan: Community (default) 40,000 tracking, 1,000 locator, 10,000 void calls a month; Power User and Enterprise as plans | 2026-11-10 |
| omnibus/fedex | 1,400 per 10 seconds (every call), 100,000 tracking calls a day | 2026-11-10 |
| omnibus/dhl | Location Finder: 500 a day, 1 a second; MyDHL: none published | 2026-11-10 |
| omnibus/usps | unknown: the terms say a daily quota exists, without a figure | 2026-11-10 |
| omnibus/canada-post | per key and minute: shipments 646, voids 468, rates 840, tracking 20, post offices 468 | 2026-11-10 |
| omnibus/auspost | tracking: 10 a minute | 2026-11-10 |
| omnibus/amazon | 80 a second, burst 100 (Shipping API v2 usage plan) | 2026-11-10 |
| omnibus/mondial-relay | unknown; a shipment tracked 4 to 6 times a day at most (in the notes) | 2026-11-10 |
| omnibus/tnt | unknown; ExpressConnect is being retired | 2026-11-10 |
| omnibus/colissimo | none: the package calls no service yet | 2026-11-10 |
| omnibus/offline | none: no carrier | - |
| omnibus/royal-mail, purolator, canpar, chronopost, gls, aramex | unknown: no terms readable on 2026-11-10 | - |
| omnibus/db-schenker, bluedart, dtdc, sf-express, jdl-express, zto-express | unknown: the API calls are unverified | - |

The rights carry what the terms say of the data: UPS keeps shipping information 9 months at
most and shares it only with those who have an interest in the shipment; DHL lets nothing be
stored but what is technically necessary; Canada Post wants personal information destroyed once
used; Amazon keeps personal data 30 days after delivery (`rights.retention`). Each policy's
`provenance.notes` says what was read and what was left out.

## Reading and tightening a policy

```php
$registry = new Registry($factories, [
    'ups' => [
        'factory' => 'ups',
        'options' => ['client_id' => '...', 'client_secret' => '...', 'account_number' => '...'],
        'policy' => ['rateLimits' => [['max' => 100, 'window' => 'day', 'operations' => ['ship']]]],
        'usage' => ['commercial' => true, 'contract' => true],
    ],
], $sharedLimiter);

$registry->policy('ups');                              // UPS's, tightened by the site's
$registry->policies();                                 // every gateway's
$registry->get('ups')->capabilities()->contracts();    // ['rate', 'ship', 'track', 'pickupPoints', ...]
```

A site may only tighten a policy: a looser limit is an `InvalidPolicyException` from
omnisense/contracts. An operation is `GatewayInterface`'s method name (`rate`, `ship`, `track`,
`pickupPoints`, `slip`, `cancel`), plus `paperless` (`Gateway::OPERATIONS`). A carrier with plans
(UPS) is run under its default plan; the `plan` option names another.

## The guard

`Registry::get()` gives the gateway as before. `Registry::guarded($name)` gives it behind its
policy: before a request is sent, each limit counting its operation is checked. A limit reached
refuses the request with `UsageLimitReachedException`, and nothing is sent. The request is
counted after, answered or failed.

The limiter counts in memory by default. An application served by many processes gives the
registry a shared one (its third argument; in Symfony, `omnibus.limiter`): any
`Omnisense\Contracts\Policy\RateLimiterInterface`.

## A 429, and a label never bought twice

Whether the gateway is guarded or not, each carrier's API client honours the waits the carrier
asks for:

- **A 429** is a `RateLimitedException`. It is a `CarrierException`, so existing catches still hold
  it, with `carrierCode` `rate_limited` and `retryAfter` in seconds when the carrier said
  (`Retry-After`, or the `RateLimit` field).
- **The next calls** to that carrier wait as long. They are refused with
  `UsageLimitReachedException` before anything is sent: in every process when the limiter is
  shared.
- **Nothing retries a call**: neither the guard, the wait, nor the API clients. The HTTP client a
  factory is given is kept with its retries off (`Http\NoRetry`): Symfony's
  `RetryableHttpClient` (`framework.http_client`'s `retry_failed`) would send a label's purchase
  again on a 429, 502 or 503.
- **A shipment booked, its label held**: where a carrier books then fetches the label in a second
  call (Canada Post, Royal Mail, Purolator, SF Express, JDL, TNT), a wait that holds the second
  call leaves the label without its document, as a failed fetch did: the booking stands, its
  label is fetched later (`slip()` or the carrier's site). TNT's result fetch held is a
  `CarrierException` naming the access code: the consignment went to TNT.
- **Idempotency.** Amazon Shipping's purchase takes `x-amzn-IdempotencyKey`: give the shipment an
  `idempotency_key` option, one per attempt kept with it, and a purchase made again after a
  failure buys one label. TNT refuses a consignment number sent twice. The other carriers offer
  no idempotency key on shipments or cancellations: a failure after the request left (a timeout,
  a 5xx) may come after the carrier booked it. Look the shipment up (`track()`, the carrier's
  site) before booking again.

```php
try {
    $label = $registry->get('amazon')->ship($shipment);   // $shipment->options['idempotency_key']: the attempt's
} catch (RateLimitedException|UsageLimitReachedException $e) {
    // Nothing was bought: try again in $e->retryAfter seconds, with the same shipment.
}
```
