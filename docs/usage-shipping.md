---
title: Shipments and labels
order: 32
---

## Shipments and labels

```php
$label = $gateway->ship($shipment);     // Label: tracking number, the document
```

`ship()` books the shipment with the carrier and answers the label it issued. Booking is the one
call that commits the shop (a carrier invoices booked shipments): ask for rates and let the
customer choose before, book once the order is paid.

### Booking

```php
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;

$shipment = new Shipment(
    sender: new Address('Glitch Art', ['12 rue de la Paix'], '75002', 'Paris', 'FR', company: 'Glitch Art', phone: '+33102030405', email: 'shop@example.org'),
    recipient: new Address('Émile Zola', ['21 bis rue de Bruxelles', 'Bâtiment B'], '75009', 'Paris', 'FR', email: 'emile@example.org', phone: '0612345678'),
    parcels: [new Parcel(800, 30, 20, 10, value: 4500)],
    service: $chosenRate->service,          // from rate(); null: the carrier's default service
    reference: 'CMD-1042',                  // printed on the label where the carrier allows
    options: ['label_format' => 'PDF'],     // carrier-specific, see the carrier's manual
);

$label = $gateway->ship($shipment);

$order->setTrackingNumber($label->trackingNumber);
$order->setTrackingUrl($label->trackingUrl);
```

Give the recipient's phone and e-mail where you have them: the carriers that take them send them
on, for the delivery notices. Each carrier's manual lists what it sends, what it cuts (names of 35
characters, street lines...) and the shipment options it reads.

### The document

A label carries its document in one of two ways, or not at all:

```php
if (null !== $label->content) {
    // the document itself, binary: a PDF, a ZPL file for a thermal printer, a GIF (UPS)
    $extension = match ($label->format) {
        Omnibus\Model\Label::PDF => 'pdf',
        Omnibus\Model\Label::ZPL => 'zpl',
        'image/gif' => 'gif',
        default => 'bin',
    };
    file_put_contents("labels/{$label->trackingNumber}.{$extension}", $label->content);
} elseif (null !== $label->url) {
    // a link the carrier hands out: download it while it is valid
} else {
    // no document: the shop writes the address itself (offline), or the carrier's own
    // client prints it (ZTO's electronic waybill)
}
```

`Label::$format` is a media type; `Label::PDF` (`application/pdf`) is the default, `Label::ZPL`
(`application/zpl`) is asked for with the carrier's `label_format` option where it supports it.
Keep the document with the order: a reprint (`slip()`) is offered by few carriers
([After shipping](usage-after-shipping.md)).

### To a pickup point

```php
$points = $gateway->pickupPoints($shipment->recipient, 5);   // the customer picks one

$shipment = new Shipment($sender, $recipient, $parcels, pickupPoint: $points[0]->id);
$label = $gateway->ship($shipment);
```

The carrier delivers to `pickupPoint`, the id one of its own `PickupPoint`s answered. Each
carrier's manual says how the id is sent (Mondial Relay takes `FR-066974`) and which services
deliver to points (`Rate::$toPickupPoint`).

### Several parcels

A shipment of several parcels is booked in one call where the carrier allows it; the label then
has the shipment's (master) tracking number. Carriers differ in what they do with each parcel's
weight and size: see each carrier's manual.

### Without a carrier

`omnibus/offline` books nothing: its label's tracking number is the shipment's
`tracking_number` option (typed from the post office's receipt), else its `reference`, else a
random 12-character number; its `trackingUrl` is the `tracking_url` option with `{number}`
replaced; it has no document.

```php
$label = $registry->get('boutique')->ship(new Shipment($shop, $customer, [new Parcel(500)], options: ['tracking_number' => '6A12345678901']));
// 6A12345678901, https://www.laposte.fr/outils/suivre-vos-envois?code=6A12345678901
```
