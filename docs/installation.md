---
title: Installation
order: 10
---

## Installation

```sh
composer require omniscient/omnibus omnibus/offline       # no carrier: the shop's own rates
composer require omnibus/mondial-relay omnibus/ups ...    # a carrier's API, one package each
```

### Requirements

- PHP 8.2 or later.
- `omniscient/omnibus` requires `symfony/http-client-contracts` (^3.0) and nothing else.
- A carrier that calls an API requires `symfony/http-client` (^6.4, ^7.0 or ^8.0), a library that
  stands alone and loads no framework. `omnibus/offline` and `omnibus/colissimo` call nothing and
  require neither.
- No `ext-soap`: the SOAP carriers (Chronopost, Purolator, Canpar) write and read their XML
  themselves. `omnibus/mondial-relay` requires `ext-simplexml`, enabled in most PHP builds.

Omnibus runs the same in plain PHP, in a worker, in Laravel or Slim, and in Symfony, where a bundle
does the wiring ([Symfony](symfony.md)).

### Plain PHP

A whole script, with no carrier and no network: the shop's own prices, a tracking number typed at
the post office counter, click and collect at the shop.

```php
<?php // bare.php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Offline\OfflineGatewayFactory;
use Omnibus\Registry;

$registry = new Registry([new OfflineGatewayFactory()], [
    'boutique' => ['factory' => 'offline', 'options' => [
        // The shop's own prices, by weight (grams => cents)
        'rates' => [
            ['service' => 'courier', 'label' => 'Coursier', 'currency' => 'EUR', 'bands' => [1000 => 690, 30000 => 1290]],
            ['service' => 'pickup', 'label' => 'Retrait en boutique', 'to_pickup_point' => true, 'bands' => [30000 => 0]],
        ],
        'tracking_url' => 'https://www.laposte.fr/outils/suivre-vos-envois?code={number}',
        'pickup_points' => [['id' => 'shop', 'name' => 'La boutique', 'street' => ['1 rue de Rivoli'], 'postcode' => '75001', 'city' => 'Paris', 'country' => 'FR']],
    ]],
]);
$gateway = $registry->get('boutique');

$shipment = new Shipment(
    new Address('La boutique', ['1 rue de Rivoli'], '75001', 'Paris', 'FR'),
    new Address('Camille Durand', ['12 rue des Juifs'], '67000', 'Strasbourg', 'FR'),
    [new Parcel(500)],                                    // grams
    options: ['tracking_number' => '6A12345678901'],      // typed by hand at the counter
);

foreach ($gateway->rate($shipment) as $rate) {
    printf("%-20s %5.2f %s\n", $rate->label, $rate->amount / 100, $rate->currency);
}

$label = $gateway->ship($shipment);
echo "\n", $label->trackingNumber, ' ', $label->trackingUrl, "\n";
echo $gateway->track($label->trackingNumber)->status->value, "\n";

foreach ($gateway->pickupPoints($shipment->recipient) as $point) {
    echo $point->id, ': ', $point->name, ', ', $point->address->postcode, ' ', $point->address->city, "\n";
}
```

```
$ php bare.php
Retrait en boutique   0.00 EUR
Coursier              6.90 EUR

6A12345678901 https://www.laposte.fr/outils/suivre-vos-envois?code=6A12345678901
unknown
shop: La boutique, 75001 Paris
```

(run on 2026-10-25 in the [harness](harness.md), PHP 8.4; first run on 2026-10-05 in an empty
directory after `composer require omniscient/omnibus omnibus/offline`: three packages installed,
`symfony/http-client-contracts` the only one that is not Omnibus's)

No key, no network: the shop hands the parcel over itself, so its tracking is the number typed and
its state is not known to anyone (`unknown`). The pieces:

- a **factory** per carrier package (`OfflineGatewayFactory`, `MondialRelayGatewayFactory`,
  `UpsGatewayFactory`...). One that calls an API takes the HTTP client to call with - the
  application's, a `MockHttpClient` in a test - and with none given makes its own
  (`HttpClient::create()`);
- the **registry**, built from the factories and the gateways' options, by name: `get()`, and
  `create($name, $overrides)` for keys typed in a back office;
- the **gateways** it gives: `rate()`, `ship()`, `track()`, `pickupPoints()`, and for the carriers
  that do them `slip()` and `cancel()` - `supports()` says beforehand what a gateway does.

No class of a framework is loaded on the way: `Tests/BareTest.php` checks it in a process of its
own, and so does `docker compose run --rm omnibus bare` ([harness](harness.md)).

### A carrier's API

```php
use Omnibus\Ups\UpsGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$ups = (new UpsGatewayFactory(HttpClient::create()))->create([
    'client_id' => getenv('UPS_CLIENT_ID'),
    'client_secret' => getenv('UPS_CLIENT_SECRET'),
    'account_number' => getenv('UPS_ACCOUNT'),
    'sandbox' => true,                               // the Customer Integration Environment
]);

$rates = $ups->rate($shipment);                      // Rate[], cheapest first
$label = $ups->ship($shipment);                      // tracking number, the label's content and format
$ups->track($label->trackingNumber);                 // status, events
$ups->pickupPoints($shipment->recipient, 10);        // Access Points near an address
```

Building a gateway calls nothing: a carrier's token is asked for on the first call, and a missing
required option is reported by `create()` as an `InvalidConfigException` naming it
(`The "ups" gateway needs: client_secret, account_number.`).

Two carriers publish test identifiers for everyone, usable without a contract: Mondial Relay's test
brand (`['sandbox' => true]` fills `enseigne` and `private_key` with `BDTEST13` and `PrivateK`) and Chronopost's test
account (`ChronopostGatewayFactory::TEST_ACCOUNT`, `::TEST_PASSWORD`). They are test identifiers,
not secrets.

### In a framework

- **Symfony**: `Omnibus\Bridge\Symfony\OmnibusBundle` registers the factories on the application's
  `http_client`, builds the registry from `config/packages/omnibus.yaml` and makes each gateway
  injectable by its name: [Symfony](symfony.md).
- **Laravel**: no bridge package is needed; a service provider builds the registry once:
  [Laravel](laravel.md).
- **Any other**: build the `Registry` once, where the framework builds its services (a container
  definition, a factory), as the script above does.
