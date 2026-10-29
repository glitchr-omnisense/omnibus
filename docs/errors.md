---
title: Errors
order: 50
---

# Errors

Every exception Omnibus throws implements `Omnibus\Exception\OmnibusException` (a `\Throwable`):

| Exception | Extends | When |
|---|---|---|
| `RequestNotSupportedException` | `\LogicException` | the gateway has no action for the request: `supports()` says so beforehand. Thrown before any call. Message: `The "dhl" gateway does not support Pickup.` |
| `InvalidConfigException` | `\LogicException` | a gateway not configured, a factory not installed, a required option missing or empty, an action given an API client of the wrong class |
| `CarrierException` | `\RuntimeException` | the carrier refused, or could not be reached; an action that left its request unanswered |

`Shipment`'s constructor throws `\InvalidArgumentException` (not an `OmnibusException`) when given
no parcel.

## CarrierException

```php
use Omnibus\Exception\CarrierException;

try {
    $label = $gateway->ship($shipment);
} catch (CarrierException $e) {
    $e->carrier;        // the gateway's name: "ups", "mondial_relay"
    $e->carrierCode;    // the carrier's own error code, when it gave one; else null
    $e->getMessage();   // "[ups] <the carrier's message>"
    $e->getPrevious();  // a copy of the HTTP client's exception, the JSON error... when there was one
}
```

The message is prefixed with the carrier's name in brackets. The family's rule, which every
carrier keeps and the conformance suite checks on each operation that calls the carrier
([Writing a carrier](providers.md#conformance-suite)): every failure of the carrier is a
`CarrierException` naming it -

- an HTTP status of 400 or more: 401 and 403 (a credential refused), 404, 429 (too many
  requests), 5xx;
- a network error (refused, timed out, DNS);
- a body the carrier's client cannot read (an HTML error page from a proxy with a 200 included);
- a login or token refused.

Never an HTTP client's own exception, never a `\TypeError` from an unexpected shape, and never an
empty answer in place of the failure: no rates because the carrier is down is an exception, not
`[]`.

## No secret in a failure

What a carrier throws keeps the request's secrets out, for a log that writes the whole chain,
arguments included (`zend.exception_ignore_args` off). The HTTP client's message quotes the URL
called: in the `CarrierException`'s message its key parameters, a password in its user part and every secret
of the request (a key, a password, a bearer or basic credential) are masked as `***`
(`Omnibus\Http\Redaction::text()`). The previous exception is a copy of the client's
(`Redaction::copy()`: the same class and code, that message, its own previous copied the same
way, without the response or request a client's exception holds), and no trace of the chain keeps
its arguments, where the options, headers and body stood (`Redaction::withoutArguments()`). A
`GatewayFactory::create()` that fails (a missing option) throws without arguments in its trace
either: they held the options, credentials included.

## What to catch where

| Where | Catch |
|---|---|
| a checkout showing rates | `CarrierException`: show configured or flat prices, or "unavailable"; log it |
| booking after payment | `CarrierException`: keep the order, retry later (a 429 or 5xx is often temporary), alert someone on a 401/403 |
| building the registry, a deploy | `InvalidConfigException`: a configuration error, to fix, not to retry |
| a feature a carrier may lack | check `supports()` first; `RequestNotSupportedException` is a programming error |

A carrier's message and code are the carrier's, untranslated: show the customer a message of the
shop's, keep the carrier's for the logs.
