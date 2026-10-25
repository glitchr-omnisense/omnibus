---
title: Symfony
order: 15
---

## Symfony

Omnibus runs without a framework ([Installation](installation.md)); in a Symfony application its
bundle does the wiring. The bundle's components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` (6.4, 7 or 8) - are not required by `omniscient/omnibus`: the application has
them, and nothing of them is loaded outside Symfony.

### Registering the bundle

There is no Flex recipe: add the bundle by hand.

```php
// config/bundles.php
return [
    // ...
    Omnibus\Bridge\Symfony\OmnibusBundle::class => ['all' => true],
];
```

### Configuration

```yaml
# config/packages/omnibus.yaml
omnibus:
    gateways:                      # by name: a factory and its options
        relais:
            factory: mondial_relay
            options: { enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%', private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%' }
        retrait:
            factory: offline
            options:
                rates:
                    - { service: pickup, label: 'Retrait en boutique', to_pickup_point: true, bands: { 30000: 0 } }
```

```sh
# .env.local, or bin/console secrets:set
MONDIAL_RELAY_ENSEIGNE=...
MONDIAL_RELAY_PRIVATE_KEY=...
```

The whole tree:

| Key | Type | Default | |
|---|---|---|---|
| `omnibus.gateways` | map, keyed by the gateway's name | `{}` | the shop's carriers |
| `omnibus.gateways.<name>.factory` | string, required, not empty | - | a factory's name: `offline`, `mondial_relay`, `ups`... ([carriers](index.md#carriers)), or an application's own factory's `getName()` |
| `omnibus.gateways.<name>.options` | any value (a map) | `[]` | handed as they are to the factory's `create()`: the carrier's options ([Configuration](configuration.md)) |

The options are not validated when the container compiles - the tree accepts any value - but
when the gateway is built: a required option missing or empty is an `InvalidConfigException`
naming it, the first time the gateway is asked for.

### Services

Every `omnibus/*` carrier package installed has its factory registered as a service, built on the
application's `http_client` service (or with none, when the application has no HTTP client
service: the factory then makes its own). The bundle knows the 23 carriers of the
[table](index.md#carriers) and registers those whose class exists.

| Service id | Class | Autowiring | |
|---|---|---|---|
| `Omnibus\Registry` | `Registry` | `Registry $registry` | every configured gateway by name: `get()`, `has()`, `names()`, `options()`, `all()`, `create()` with other options. Public. |
| `omnibus.gateway.<name>` | `GatewayInterface` | `GatewayInterface $<name>` | one gateway, built by `Registry::get('<name>')` the first time it is injected |
| `Omnibus\<Carrier>\<Carrier>GatewayFactory` | the carrier's factory | by its class | tagged `omnibus.gateway_factory` |

```php
use Omnibus\GatewayInterface;

final class ShippingController
{
    public function __construct(private readonly GatewayInterface $relais)   // the "relais" gateway
    {
    }
}
```

The argument's name selects the gateway: `GatewayInterface $relais` is `omnibus.gateway.relais`
(Symfony's named autowiring aliases, `registerAliasForArgument()`). A gateway name that is not a
valid PHP variable name (`mondial-relay`) is still reachable by its service id or by the registry.

Nothing is built when the container compiles: a gateway is built the first time it is asked for,
and an option left empty shows only then. Keys typed in a back office rather than set in the
environment: `$registry->create('relais', ['private_key' => $stored])`, a gateway built afresh with
those options over the configured ones and not kept by the registry.

### An application's own carrier

A class of the application that implements `Omnibus\GatewayFactoryInterface` (usually by extending
`Omnibus\GatewayFactory`) is autoconfigured: tagged `omnibus.gateway_factory`, it joins the
registry, and its `getName()` can be named as a `factory`:

```yaml
omnibus:
    gateways:
        coursier:
            factory: my_courier     # App\Shipping\MyCourierGatewayFactory::getName()
```

The bundle registers no command, event, Twig function or route: the gateways are services, used
from the application's controllers, message handlers and commands.
