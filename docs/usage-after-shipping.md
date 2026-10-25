---
title: Slips, cancellations and customs documents
order: 35
---

## Slips, cancellations and customs documents

### Printing a label again

```php
use Omnibus\Model\Label;
use Omnibus\Request\GetSlip;

if ($gateway->supports(GetSlip::class)) {
    $label = $gateway->slip($trackingNumber, Label::PDF);
}
```

`slip()` asks the carrier for the label of a shipment booked earlier. Only Mondial Relay answers it
today; for every other carrier, keep the document `ship()` returned (`Label::$content`, or download
`Label::$url` while it is valid).

### Cancelling a shipment

```php
use Omnibus\Request\Cancel;

if ($gateway->supports(Cancel::class) && $gateway->cancel($label->trackingNumber)) {
    // voided: the carrier will not invoice it
}
```

`cancel()` voids a shipment not handed over yet and answers `true` when the carrier voided it,
`false` when the carrier answered that it did not (handed over already, too late; how each carrier
says so is in its manual - Amazon answers `true` whenever the call succeeds). Chronopost,
GLS, Purolator, Canpar, Australia Post, Blue Dart, DTDC, SF Express, JD Logistics, ZTO and Amazon
answer it.

> [!WARNING]
> Known defect: SF Express cancels with the waybill number as the order id, while the order was
> created under the shipment's reference or a random id; Amazon's `cancel()` needs the shipment id,
> which the `Label` does not keep (it carries the package's tracking id). Neither cancellation
> can reach the shipment as written; see those carriers' manuals.

### Customs documents

```php
use Omnibus\Request\Paperless;

$request = new Paperless($trackingNumber, ['invoice' => file_get_contents('invoice.pdf')]);
if ($gateway->supports(Paperless::class)) {
    $accepted = (bool) $gateway->execute($request)->getResult();
}
```

`Paperless` sends customs documents electronically (paperless trade) for a shipment leaving the
customs union: document type (`invoice`, `proforma`...) => PDF content. It is part of the contract,
with no typed shortcut on the gateway, and no carrier answers it yet: `supports()` is `false`
everywhere.

### Any request, by hand

Every typed method is a shortcut for `execute()`:

```php
use Omnibus\Request\Rating;

$request = $gateway->execute(new Rating($shipment));
$rates = $request->getRates();
```

`execute()` hands the request back answered, or throws `RequestNotSupportedException` (no action
supports it) or `CarrierException` (the carrier failed, or the action left it unanswered).
