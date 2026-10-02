# glitchr/omnibus

One contract for parcel carriers - rates, labels, tracking, pickup points - the Payum of shipping.

```php
$gateway = $registry->get('relais');
$gateway->rate($shipment);                 // Rate[]
$gateway->ship($shipment);                 // Label: tracking number, PDF
$gateway->track('12345678');               // Tracking: status, events
$gateway->pickupPoints($address, 10);      // PickupPoint[]
```

This package holds the contract (`GatewayInterface`, `GatewayFactory`, `Registry`), the models
(`Shipment`, `Parcel`, `Address`, `Rate`, `Label`, `Tracking`, `PickupPoint`...), the requests and the
Symfony bundle. Each carrier is a package of its own:

| Package | Carrier |
|---|---|
| `omnibus/offline` | No carrier: the shop's own rates, a tracking number typed by hand, click & collect |
| `omnibus/mondial-relay` | Mondial Relay: relay points, labels, tracking (no ext-soap needed) |
| `omnibus/colissimo` | Colissimo (La Poste): rates from configuration, web services to come |
| `omnibus/chronopost` | Chronopost: Quickcost prices, skybills, tracking, Pickup relays (SOAP) |
| `omnibus/ups` | UPS: rates, labels, tracking, Access Points (OAuth2 REST) |
| `omnibus/fedex` | FedEx: rate quotes, labels, tracking, locations (OAuth2 REST) |
| `omnibus/dhl` | DHL Express: rates, labels, tracking (MyDHL API), service points (Location Finder) |
| `omnibus/tnt` | TNT: prices, consignments, tracking (ExpressConnect, existing accounts) |
| `omnibus/gls` | GLS: shipments and labels (ShipIT), cancellation, public tracking; configured rates |
| `omnibus/db-schenker` | DB Schenker: public tracking; bookings (Open API, unverified); configured rates |
| `omnibus/usps` | USPS: prices, domestic labels, tracking, locations (APIs v3) |
| `omnibus/royal-mail` | Royal Mail: Click & Drop orders and labels, Tracking API; configured rates |
| `omnibus/canada-post` | Canada Post: rates, (non-)contract shipments, tracking, post offices (XML REST) |
| `omnibus/purolator` | Purolator: estimates, shipments and documents, tracking, locations, voids (SOAP) |
| `omnibus/canpar` | Canpar: rates, shipments and labels, tracking, voids (CanShip SOAP) |
| `omnibus/auspost` | Australia Post: prices, shipments and labels, tracking, cancellation |
| `omnibus/aramex` | Aramex: rates, shipments and labels, tracking, offices (JSON web services) |
| `omnibus/bluedart` | Blue Dart: transit times, waybills and labels, tracking, cancellation (unverified) |
| `omnibus/dtdc` | DTDC: consignments and labels, tracking, cancellation; configured rates (unverified) |
| `omnibus/sf-express` | SF Express: orders, cloud-print labels, routes, cancellation; configured rates (unverified) |
| `omnibus/jdl-express` | JD Logistics: orders, labels, traces, cancellation; configured rates (unverified) |
| `omnibus/zto-express` | ZTO Express: orders with electronic waybills, traces, cancellation; configured rates (unverified) |
| `omnibus/amazon` | Amazon Shipping: rates, purchased shipments and labels, tracking, cancellation (SP-API) |

A carrier's factory fills a `Config` - its name, options, API client and actions - and the gateway runs
the actions that support each request. A `rates` option always adds prices from configuration
(`Action\ConfiguredRatingAction`) when the carrier has no rating service.

## Symfony

`Omnibus\Bridge\Symfony\OmnibusBundle`: every `omnibus/*` carrier installed registered, `Omnibus\Registry`
autowired, and each configured gateway injectable by its name.

```yaml
omnibus:
    gateways:
        relais:
            factory: mondial_relay
            options: { enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%', private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%' }
        retrait:
            factory: offline
```

```php
public function __construct(GatewayInterface $relais) {}
```

An application's own `GatewayFactoryInterface` is registered too (autoconfigured).

## Docker: every carrier with your test keys

`docker/` runs this package with every `omnibus/*` carrier installed - from GitHub, or from the
checkouts beside this one when `OMNIBUS_PLUGINS=../..` is set - and a console that exercises them
with the keys in `docker/.env` (copy `.env.dist`; `docker compose run --rm omnibus gateways` says
which carriers are configured and what each one does):

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnibus gateways
docker compose run --rm omnibus rate ups --to-country GB --weight 1200
docker compose run --rm omnibus ship chronopost --service 01      # the label lands in docker/labels/
docker compose run --rm omnibus track ups 1Z999AA10123456784
docker compose run --rm omnibus pickup mondial_relay --to-postcode 75009
docker compose run --rm omnibus test                               # every package's tests
```

License: LGPL-3.0-or-later.
