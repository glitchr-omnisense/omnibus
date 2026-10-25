---
title: Rates
order: 31
---

## Rates

```php
$rates = $gateway->rate($shipment);     // Rate[], cheapest first
```

`rate()` answers every service the carrier can carry the shipment with, and its price. A carrier
with a rating API asks it (UPS's `Shop`, FedEx's rate quotes, Chronopost's Quickcost...); a carrier
without one, or a gateway with a `rates` option, prices from configuration with no call.

### Pricing a shipment

```php
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;

$shipment = new Shipment(
    sender: new Address('La boutique', ['1 rue de Rivoli'], '75001', 'Paris', 'FR'),
    recipient: new Address('Camille Durand', ['12 rue des Juifs'], '67000', 'Strasbourg', 'FR'),
    parcels: [new Parcel(800), new Parcel(1500, 40, 30, 20)],
);

foreach ($gateway->rate($shipment) as $rate) {
    printf(
        "%s %-24s %6.2f %s%s%s\n",
        $rate->service,
        $rate->label,
        $rate->amount / 100,
        $rate->currency,
        null !== $rate->days ? ", {$rate->days} days" : '',
        $rate->toPickupPoint ? ', to a pickup point' : '',
    );
}
```

Show the rates to the customer, keep the chosen `Rate::$service`, and book with it
(`new Shipment(..., service: $rate->service)`, [Shipments and labels](usage-shipping.md)).

What every rating answer keeps:

- each `Rate::$carrier` is the gateway's name;
- amounts are integers in minor units, in the ISO 4217 currency the carrier or the configuration
  gives;
- the list is sorted cheapest first;
- an empty list means the carrier serves none of its services for that shipment (too heavy, a
  country not served). A carrier that could not be reached is a `CarrierException`, never `[]`.

### Configured prices

A shop often has negotiated prices that no public API returns, or a carrier with no rating API at
all (Mondial Relay, Colissimo, GLS, Royal Mail...). The `rates` option gives the gateway a price
grid by weight:

```php
$registry = new Registry([new MondialRelayGatewayFactory($http)], [
    'relais' => ['factory' => 'mondial_relay', 'options' => [
        'enseigne' => '...', 'private_key' => '...',
        'rates' => [
            ['service' => '24R', 'label' => 'Point Relais', 'to_pickup_point' => true, 'days' => 4,
             'countries' => ['FR', 'BE'], 'bands' => [500 => 490, 1000 => 590, 5000 => 890]],
            ['service' => 'HOM', 'label' => 'Domicile', 'bands' => [1000 => 790, 5000 => 1090]],
        ],
    ]],
]);
```

Each parcel is priced on its own band (the first band whose weight it does not exceed) and the
parcels' prices are added; a parcel heavier than the last band, or a recipient country not in
`countries`, gives no rate for that service. When `Shipment::$service` is set, only that service is
priced. The full grammar is in [Configuration](configuration.md#the-rates-option).

With `rates` set, a carrier that has a rating API does not register its own rating action: the
configured prices replace the API, and `rate()` calls nothing. With the harness's offline gateway
(bands `1000 => 690, 30000 => 1290`), a shipment of 800 g and 1500 g under `courier` costs
`690 + 1290 = 1980`.

### Asking for one service

`Shipment::$service` narrows configured rates to that service. Carriers' rating APIs differ: most
price every service whatever `service` says (UPS's `Shop`), some take it into account; each
carrier's manual says which.

### When the carrier has no rates

```php
use Omnibus\Request\Rating;

if (!$gateway->supports(Rating::class)) {
    // no rating API and no `rates` option: show a flat price of the shop's, or configure `rates`
}
```

Calling `rate()` there throws `RequestNotSupportedException` before any call
([Errors](errors.md)).
