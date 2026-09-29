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

License: LGPL-3.0-or-later.
