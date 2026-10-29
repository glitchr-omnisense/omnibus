# omnisense/omnibus

Omnibus is one PHP contract for parcel carriers. A shop asks every carrier the same questions -
what does this shipment cost, book it and give me the label, where is this parcel, which pickup
points are near this address, print the label again, cancel it - and gets the same answers back:
`Rate`, `Label`, `Tracking`, `PickupPoint`. Each carrier is a package of its own (`omnibus/ups`,
`omnibus/mondial-relay`...) that turns those questions into the carrier's API calls.

```php
$gateway = $registry->get('relais');

$gateway->rate($shipment);                 // Rate[], cheapest first
$gateway->ship($shipment);                 // Label: tracking number, the document
$gateway->track('12345678');               // Tracking: status, events oldest first
$gateway->pickupPoints($address, 10);      // PickupPoint[], nearest first
$gateway->slip('12345678');                // Label, printed again
$gateway->cancel('12345678');              // true when the carrier voided it
```

The design is Payum's: a carrier's **factory** fills a configuration with its name, its options,
its API client and one **action** per request it answers; the **gateway** it builds runs the action
that supports each request; a **registry** holds the shop's gateways by name. The core needs no
framework: it requires `symfony/http-client-contracts` and nothing else. A carrier that calls an API
requires `symfony/http-client`, a library that stands alone. A Symfony bundle is included for
applications that want the wiring done ([Symfony](symfony.md)).

Published as `omnisense/omnibus`, formerly `glitchr/omnibus`: the package `replace`s the old name,
so a `composer.json` that still requires `glitchr/omnibus` resolves to this package and never
installs both. The namespace is `Omnibus\`; carriers keep the `omnibus/` vendor and the
`Omnibus\<Carrier>\` namespace.

## When to use it

- A shop or a back office that ships with more than one carrier, or wants to be able to change
  carrier without rewriting its checkout and its order screens.
- Prices that come from a carrier's API for some carriers and from the shop's own grid for others
  (the `rates` option, [Configuration](configuration.md#the-rates-option)).
- Click and collect, or parcels the shop hands over itself: `omnibus/offline` answers the same
  contract without any carrier.

It is not a multi-carrier aggregator service (no account, no billing of its own): each gateway
calls its carrier with the shop's own contract and credentials. It does not store anything either:
saving the tracking number and the label is the application's job.

## Carriers

Twenty-three packages. "Configured rates" means the carrier has no rating action and prices come
only from the `rates` option. The capabilities are what each gateway's `supports()` answers with
its required options set (probed in the [harness](harness.md) on 2026-10-25); "with X" means the
request is supported only once option X is set.

| Package | Factory name | Rate | Ship | Track | Pickup points | Slip | Cancel |
|---|---|---|---|---|---|---|---|
| [`omnibus/offline`](https://github.com/glitchr-omnisense/omnibus-offline) | `offline` | configured | yes | yes (status unknown) | yes (configured) | - | - |
| [`omnibus/mondial-relay`](https://github.com/glitchr-omnisense/omnibus-mondial-relay) | `mondial_relay` | configured | yes | yes | yes | yes | - |
| [`omnibus/colissimo`](https://github.com/glitchr-omnisense/omnibus-colissimo) | `colissimo` | configured | - | - | - | - | - |
| [`omnibus/chronopost`](https://github.com/glitchr-omnisense/omnibus-chronopost) | `chronopost` | yes | yes | yes | yes | - | yes |
| [`omnibus/ups`](https://github.com/glitchr-omnisense/omnibus-ups) | `ups` | yes | yes | yes | yes | - | - |
| [`omnibus/fedex`](https://github.com/glitchr-omnisense/omnibus-fedex) | `fedex` | yes | yes | yes | yes | - | - |
| [`omnibus/dhl`](https://github.com/glitchr-omnisense/omnibus-dhl) | `dhl` | yes | yes | yes | with `location_api_key` | - | - |
| [`omnibus/tnt`](https://github.com/glitchr-omnisense/omnibus-tnt) | `tnt` | yes | yes | yes | - | - | - |
| [`omnibus/gls`](https://github.com/glitchr-omnisense/omnibus-gls) | `gls` | configured | yes | yes | - | - | yes |
| [`omnibus/db-schenker`](https://github.com/glitchr-omnisense/omnibus-db-schenker) | `db_schenker` | configured | with `api_key` and `account_number` | yes | - | - | - |
| [`omnibus/usps`](https://github.com/glitchr-omnisense/omnibus-usps) | `usps` | yes | with `crid`, `mid`, `account` | yes | yes | - | - |
| [`omnibus/royal-mail`](https://github.com/glitchr-omnisense/omnibus-royal-mail) | `royal_mail` | configured | yes | with `tracking_client_id` and `tracking_client_secret` | - | - | - |
| [`omnibus/canada-post`](https://github.com/glitchr-omnisense/omnibus-canada-post) | `canada_post` | yes | yes | yes | yes | - | - |
| [`omnibus/purolator`](https://github.com/glitchr-omnisense/omnibus-purolator) | `purolator` | yes | yes | yes | yes | - | yes |
| [`omnibus/canpar`](https://github.com/glitchr-omnisense/omnibus-canpar) | `canpar` | yes | yes | yes | - | yes | yes |
| [`omnibus/auspost`](https://github.com/glitchr-omnisense/omnibus-auspost) | `auspost` | yes | yes | yes | - | yes | yes |
| [`omnibus/aramex`](https://github.com/glitchr-omnisense/omnibus-aramex) | `aramex` | yes | yes | yes | yes | - | - |
| [`omnibus/bluedart`](https://github.com/glitchr-omnisense/omnibus-bluedart) | `bluedart` | yes | yes | yes | - | - | yes |
| [`omnibus/dtdc`](https://github.com/glitchr-omnisense/omnibus-dtdc) | `dtdc` | configured | yes | with `tracking_username` and `tracking_password` | - | yes | yes |
| [`omnibus/sf-express`](https://github.com/glitchr-omnisense/omnibus-sf-express) | `sf_express` | configured | yes | yes | - | - | yes |
| [`omnibus/jdl-express`](https://github.com/glitchr-omnisense/omnibus-jdl-express) | `jdl_express` | configured | yes | yes | - | - | yes |
| [`omnibus/zto-express`](https://github.com/glitchr-omnisense/omnibus-zto-express) | `zto_express` | configured | yes | yes | - | - | yes |
| [`omnibus/amazon`](https://github.com/glitchr-omnisense/omnibus-amazon) | `amazon` | yes | yes | yes | - | - | yes |

Every carrier also accepts the `rates` option: set, it replaces the carrier's rating API
([Configuration](configuration.md#the-rates-option)). No carrier answers the `Paperless` request
(customs documents sent electronically) yet: the request is part of the contract, and
`supports(Paperless::class)` is `false` everywhere. Each carrier's own manual lists its options,
credentials and behaviour.

## How this manual is organised

- [Installation](installation.md): requirements, a first script in plain PHP, a carrier's API.
- [Symfony](symfony.md) and [Laravel](laravel.md): wiring the registry in a framework.
- [Concepts](concepts.md): gateways, factories, the registry, requests and actions, every model.
- Usage: [rates](usage-rating.md), [shipments and labels](usage-shipping.md),
  [tracking](usage-tracking.md), [pickup points](usage-pickup.md),
  [slips, cancellations and customs documents](usage-after-shipping.md).
- [Configuration reference](configuration.md): the registry's configuration, the `rates` option,
  every carrier's options.
- [Errors](errors.md): the three exceptions and when each is thrown.
- [Testing](testing.md): testing an application that ships with Omnibus.
- [The Docker harness](harness.md): every carrier installed, a console to call them.
- [Writing a carrier](providers.md), and its conformance suite.
- [Verified, and not](verified.md).
