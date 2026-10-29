---
title: Pickup points
order: 34
---

# Pickup points

```php
$points = $gateway->pickupPoints($address, 10, $parcel);   // PickupPoint[], nearest first
```

`pickupPoints()` answers the relays, lockers and post offices near an address that can take the
parcel: at most `$limit` (10 by default), nearest first. The parcel is optional; carriers that
filter by weight use it (Mondial Relay sends its weight), the others ignore it.

## A pickup point selector

```php
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;

$near = new Address('', [], '75009', 'Paris', 'FR');          // a postcode and a city are enough for most carriers
$points = $gateway->pickupPoints($near, 5, new Parcel(800));

foreach ($points as $point) {
    printf("%s  %s, %s %s%s\n",
        $point->id,
        $point->name,
        $point->address->postcode,
        $point->address->city,
        null !== $point->distance ? sprintf(' (%d m)', $point->distance) : '',
    );
    foreach ($point->openingHours as $weekday => $slots) {        // 1 = Monday
        echo '    ', $weekday, ': ', implode(', ', array_map(fn ($s) => "{$s[0]}-{$s[1]}", $slots)), "\n";
    }
}
```

Give the chosen `PickupPoint::$id` back as `Shipment::$pickupPoint` when booking
([Shipments and labels](usage-shipping.md#to-a-pickup-point)). `latitude` and `longitude`, where
the carrier gives them, place the points on a map.

What the contract promises - nearest first, no more than `$limit`, `distance` in metres, opening
hours by ISO weekday as `"HH:MM"` pairs - is checked by the conformance suite on recorded answers.
Carriers differ in how far they search and in what they know: some give no distance, some no
opening hours. Each carrier's manual says what its search sends and what comes back.

Carriers that number the days otherwise (UPS and Canada Post count from Sunday) or write their
times otherwise (UPS's military `930`) are converted; Canada Post's hours cost one call per office.
Aramex gives no distance and no order: its offices come by postcode, city, then distance to the
city's offices, with `distance` null.

## Click and collect

`omnibus/offline` answers the shop's own counters, configured, wherever the customer is:

```php
'retrait' => ['factory' => 'offline', 'options' => [
    'pickup_points' => [
        ['id' => 'shop', 'name' => 'La boutique', 'street' => ['1 rue de Rivoli'], 'postcode' => '75001', 'city' => 'Paris', 'country' => 'FR',
         'phone' => '+33102030405', 'latitude' => 48.8606, 'longitude' => 2.3376,
         'opening_hours' => [2 => [['10:00', '19:00']], 3 => [['10:00', '13:00'], ['14:00', '19:00']]]],
    ],
]],
```

The points come in the configured order, cut to `$limit`, with no distance.

## Carriers without pickup points

TNT, GLS, DB Schenker, Royal Mail, Canpar, Australia Post, Blue Dart, DTDC, SF Express, JD Logistics,
ZTO and Amazon answer no pickup point search; DHL answers one only with `location_api_key` (its
Location Finder's key). There `pickupPoints()` throws `RequestNotSupportedException` before any
call: check `supports(Omnibus\Request\Pickup::class)` to hide the selector.
