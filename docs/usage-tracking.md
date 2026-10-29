---
title: Tracking
order: 33
---

# Tracking

```php
$tracking = $gateway->track('1Z999AA10123456784', 'fr');   // Tracking
```

`track()` asks the carrier where a parcel is. The second argument is the locale of the events'
descriptions (`fr` by default); carriers that translate use it, the others ignore it, and each
carrier's manual says how it is sent (`fr` becomes `fr_FR` for UPS).

## Reading a tracking

```php
use Omnibus\Model\TrackingStatus;

$tracking = $gateway->track($order->getTrackingNumber());

echo $tracking->status->value, "\n";                     // in_transit, delivered...

foreach ($tracking->events as $event) {                  // oldest first
    printf("%s  %-20s %s%s\n",
        $event->at->format('Y-m-d H:i'),
        $event->status->value,
        $event->description,
        $event->location ? " ({$event->location})" : '',
    );
}

if ($tracking->status->isFinal()) {
    // delivered or returned: stop polling this parcel
}
```

- `status` is the status of the latest event, folded onto `TrackingStatus`'s eight cases
  ([Concepts](concepts.md#tracking-trackingevent-trackingstatus)); each carrier's manual has its
  mapping table.
- `events` are oldest first; `latest()` is the last one, or `null` when the carrier gave none.
- `TrackingEvent::$code` keeps the carrier's own event code, for whoever needs more than the eight
  statuses.

A carrier that reads its events' texts looks for the negative ones ("not delivered",
"undelivered") before "delivered", which they contain: a failed delivery is an `EXCEPTION`.

When the label carried a `carrierReference` (Amazon), pass it in place of the tracking number:
it tells the carrier which of its carriers to ask. The tracking's `trackingNumber` is still the
parcel's.

A tracking number the carrier does not know is, depending on the carrier, an empty tracking with
status `UNKNOWN` or a `CarrierException` carrying the carrier's error code: check each carrier's
manual.

## Polling

Omnibus has no webhook and no scheduler: an application polls. A Symfony application can do it
from a scheduled message (symfony/scheduler) or a command run by cron:

```php
foreach ($orders->inTransit() as $order) {
    $tracking = $registry->get($order->getCarrier())->track($order->getTrackingNumber());
    $order->setShippingStatus($tracking->status->value);
    if ($tracking->status->isFinal()) {
        $order->markShippingDone();
    }
}
```

Keep the gateway's name with the order (the registry's name, `relais`, `ups`...) so the parcel is
tracked by the carrier that booked it.

## Without a carrier

`omnibus/offline` answers every number with status `UNKNOWN` and no event: there is no carrier to
ask. Show the label's `trackingUrl` instead.

## Carriers whose tracking needs other credentials

Royal Mail's and DTDC's tracking APIs have credentials of their own: without
`tracking_client_id` and `tracking_client_secret` (Royal Mail) or `tracking_username` and
`tracking_password` (DTDC), `supports(Tracking::class)` is `false` and `track()` throws
`RequestNotSupportedException` before any call.
