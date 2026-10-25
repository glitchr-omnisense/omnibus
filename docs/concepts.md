---
title: Concepts
order: 20
---

# Concepts

## Gateway

`Omnibus\GatewayInterface` is one carrier, configured. Every gateway answers the same methods; each
typed method is a shortcut for `execute()` with its request.

| Method | Returns | Request |
|---|---|---|
| `getName(): string` | the factory's name (`ups`, `mondial_relay`), also the `carrier` of every model it returns | - |
| `getTitle(): string` | the carrier's display name (`UPS`, `Mondial Relay`) | - |
| `supports(string $request): bool` | whether one of the gateway's actions answers that request class | - |
| `execute(Request $request): Request` | the same request, answered | any |
| `rate(Shipment $shipment): array` | `Rate[]`, cheapest first | `Request\Rating` |
| `ship(Shipment $shipment): Label` | the label of the booked shipment | `Request\Shipping` |
| `track(string $trackingNumber, string $locale = 'fr'): Tracking` | the parcel's status and events | `Request\Tracking` |
| `pickupPoints(Address $near, int $limit = 10, ?Parcel $parcel = null): array` | `PickupPoint[]`, nearest first, at most `$limit` | `Request\Pickup` |
| `slip(string $trackingNumber, string $format = Label::PDF): Label` | a label issued earlier, again | `Request\GetSlip` |
| `cancel(string $trackingNumber): bool` | `true` when the carrier voided the shipment | `Request\Cancel` |

`Omnibus\Gateway` is the one implementation: a name, a title and a list of actions. `execute()`
hands the request to the first action whose `supports()` accepts it; when none does it throws
`RequestNotSupportedException` before anything is sent; when the action returns without setting a
result it throws a `CarrierException` (`... left the request unanswered.`). `supports()` probes the
actions with an instance of the request class built without its constructor, so it costs no call.

`Request\Paperless` (customs documents sent electronically) has no typed shortcut: it goes through
`execute()`, and no carrier answers it yet.

## Factory and Config

`Omnibus\GatewayFactoryInterface` builds a carrier's gateway from options:

| Method | |
|---|---|
| `getName(): string` | the name gateways are configured with: `offline`, `mondial_relay`, `ups`... (snake_case) |
| `create(array $options = []): GatewayInterface` | a gateway, its options validated |

Every carrier extends `Omnibus\GatewayFactory`, which takes an optional
`Symfony\Contracts\HttpClient\HttpClientInterface` in its constructor (none: the carrier calls
`HttpClient::create()` itself) and does the work in `create()`:

1. `createConfig($options)` - a `Config` (an `ArrayObject`) holding the options given, then
   `populateConfig()` fills each key the options did not set with the carrier's default;
2. the keys of `omnibus.required_options` are checked: one missing, `''` or `[]` is an
   `InvalidConfigException` listing every missing key;
3. `omnibus.api` - the carrier's API client, often a closure of the `Config` - is built;
4. every key starting with `omnibus.action.` that is (or whose closure returns) an
   `ActionInterface` becomes an action; a closure returning `null` registers no action, which is how
   a carrier makes an operation depend on an option. Actions implementing `ApiAwareInterface`
   receive the API client;
5. when the `rates` option is set, an `Action\ConfiguredRatingAction` is added last;
6. a `Gateway` is returned, named by `omnibus.factory_name` and titled by `omnibus.factory_title`.

The reserved keys a factory sets:

| Key | |
|---|---|
| `omnibus.factory_name` | the gateway's name |
| `omnibus.factory_title` | the gateway's title |
| `omnibus.required_options` | list of option names that must be filled |
| `omnibus.api` | the API client, or a `Closure(Config): object` that builds it |
| `omnibus.action.<name>` | an `ActionInterface`, or a `Closure(Config): ?ActionInterface` |

Because the options are set before the defaults, a key such as `omnibus.action.tracking` given in
the options replaces the carrier's own action. `Config::defaults()` sets only keys not set yet,
`Config::get($key, $default)` reads one, `Config::validateNotEmpty($keys)` is step 2.

## Registry

`Omnibus\Registry` holds the shop's gateways by name:

```php
$registry = new Registry(
    [new MondialRelayGatewayFactory($http), new OfflineGatewayFactory()],   // iterable of factories, keyed by getName()
    [
        'relais'  => ['factory' => 'mondial_relay', 'options' => [...]],
        'retrait' => ['factory' => 'offline'],                               // options default to []
    ],
);
```

| Method | |
|---|---|
| `get(string $name): GatewayInterface` | the gateway, built on the first call and kept |
| `has(string $name): bool` | whether a gateway of that name is configured |
| `names(): list<string>` | the configured names, in the configuration's order |
| `options(string $name): array` | the options a gateway is configured with (`[]` for an unknown name) |
| `all(): array<string, GatewayInterface>` | every configured gateway, built |
| `create(string $name, array $overrides = []): GatewayInterface` | a gateway built afresh from its configured options with `$overrides` replacing keys (`array_replace`); not kept, `get()` still answers the configured one |

An unknown name (`No "x" gateway; configured: relais, retrait.`) or a factory not installed
(`No "ups" factory for the "express" gateway; installed: mondial_relay, offline.`) is an
`InvalidConfigException`.

## Requests and actions

A request (`Omnibus\Request\Request`) is a question put to a gateway; the action that supports it
answers by calling `setResult()`. `getResult()` reads the answer, `isAnswered()` says whether there
is one (a result may legitimately be `[]` or `false`).

| Request | Constructor | Result | Typed reader |
|---|---|---|---|
| `Rating` | `Shipment $shipment` | `Rate[]` | `getRates()` (`[]` when unanswered) |
| `Shipping` | `Shipment $shipment` | `Label` | `getLabel()` |
| `Tracking` | `string $trackingNumber, string $locale = 'fr'` | `Model\Tracking` | `getTracking()` |
| `Pickup` | `Address $near, int $limit = 10, ?Parcel $parcel = null` | `PickupPoint[]` | `getPoints()` (`[]` when unanswered) |
| `GetSlip` | `string $trackingNumber, string $format = Label::PDF` | `Label` | `getLabel()` |
| `Cancel` | `string $trackingNumber` | `bool` | `getResult()` |
| `Paperless` | `string $trackingNumber, array $documents` (type => PDF content) | `bool` | `getResult()` |

An action (`Omnibus\Action\ActionInterface`) has `supports(Request): bool` and
`execute(Request): void`, which sets the result or throws a `CarrierException`. An action that
calls the carrier implements `Action\ApiAwareInterface` (`setApi(object $api)`); `ApiAwareTrait`
implements it and refuses an API client of the wrong class with an `InvalidConfigException`.

## Models

All models are `final readonly` classes in `Omnibus\Model`, built with named arguments where
convenient. Amounts are integers in minor units (cents), weights in grams, sizes in centimetres,
countries ISO 3166-1 alpha-2, currencies ISO 4217.

### Address

A postal address: the sender's, the recipient's, a pickup point's.

| Property | Type | |
|---|---|---|
| `name` | `string` | the person's name |
| `street` | `string[]` | one line each; most carriers take up to three |
| `postcode` | `string` | |
| `city` | `string` | |
| `country` | `string` | ISO 3166-1 alpha-2 (`FR`, `GB`) |
| `company` | `?string` | `null` |
| `email` | `?string` | `null` |
| `phone` | `?string` | `null`; carriers strip or reformat it as their API wants |

`line(int $index): string` returns a street line, `''` when there is none.

### Parcel

One box.

| Property | Type | Default | |
|---|---|---|---|
| `weight` | `int` | - | grams |
| `length`, `width`, `height` | `?int` | `null` | centimetres |
| `value` | `?int` | `null` | declared value, minor units (insurance, customs, by carrier) |
| `currency` | `string` | `EUR` | the value's currency |
| `reference` | `?string` | `null` | the shop's reference for this box |

`girth(): ?int` is the longest side plus twice the two others (what size bands are built on), or
`null` when a dimension is missing.

### Shipment

What goes where: from the sender to the recipient, at home or at a pickup point, in one or more
parcels, under a carrier's service.

| Property | Type | Default | |
|---|---|---|---|
| `sender` | `Address` | - | |
| `recipient` | `Address` | - | |
| `parcels` | `Parcel[]` | - | at least one: an empty list throws `\InvalidArgumentException` |
| `service` | `?string` | `null` | the carrier's service code (`11` for UPS Standard...); `null` lets the carrier choose its default, and makes rating offer every service |
| `pickupPoint` | `?string` | `null` | the id of a `PickupPoint` the parcel goes to |
| `reference` | `?string` | `null` | the order's reference, printed on the label where the carrier allows |
| `options` | `array<string, mixed>` | `[]` | carrier-specific options (label format, insurance, signature, a tracking number typed by hand...), listed in each carrier's manual |
| `shippingDate` | `?\DateTimeImmutable` | `null` | when it is handed over; unset, most carriers send today's date, DHL, TNT and DB Schenker tomorrow's |

`weight(): int` sums the parcels' weights; `option(string $name, mixed $default = null)` reads one
option.

### Rate

What a service costs for a shipment and how long it takes.

| Property | Type | Default | |
|---|---|---|---|
| `carrier` | `string` | - | the gateway's name |
| `service` | `string` | - | the service code, to put in `Shipment::$service` when booking |
| `label` | `string` | - | the service's display name |
| `amount` | `int` | - | minor units |
| `currency` | `string` | `EUR` | ISO 4217 |
| `days` | `?int` | `null` | transit days, when the carrier says |
| `toPickupPoint` | `bool` | `false` | the service delivers to a pickup point, not to the door |

### Label

The shipping label (or slip) a carrier issued.

| Property | Type | Default | |
|---|---|---|---|
| `carrier` | `string` | - | |
| `trackingNumber` | `string` | - | the number to track and to keep with the order |
| `content` | `?string` | `null` | the document itself (binary), when the carrier sends it inline |
| `format` | `string` | `Label::PDF` | its media type: `Label::PDF` (`application/pdf`), `Label::ZPL` (`application/zpl`), or another one a carrier gives (`image/gif` for UPS) |
| `url` | `?string` | `null` | where to download the document, when the carrier hands out a link instead |
| `trackingUrl` | `?string` | `null` | the carrier's public tracking page for this parcel |

A label may have neither `content` nor `url`: `omnibus/offline` prints nothing, ZTO's electronic
waybill is printed by its own client.

### Tracking, TrackingEvent, TrackingStatus

`Tracking` is where a parcel is: `carrier`, `trackingNumber`, `status` (`TrackingStatus`) and
`events` (`TrackingEvent[]`, oldest first). `latest(): ?TrackingEvent` is the last event.

`TrackingEvent`: `at` (`\DateTimeImmutable`), `status` (`TrackingStatus`), `description` (the
carrier's text, in the locale asked for when the carrier translates), `location` (`?string`),
`code` (`?string`, the carrier's own event code, kept for whoever needs the detail).

`TrackingStatus` folds every carrier's statuses onto one scale:

| Case | Value | |
|---|---|---|
| `PENDING` | `pending` | announced, not handed over yet |
| `IN_TRANSIT` | `in_transit` | |
| `OUT_FOR_DELIVERY` | `out_for_delivery` | |
| `AVAILABLE_FOR_PICKUP` | `available_for_pickup` | waiting at a pickup point |
| `DELIVERED` | `delivered` | final |
| `EXCEPTION` | `exception` | a problem: damaged, refused, address wrong... |
| `RETURNED` | `returned` | final |
| `UNKNOWN` | `unknown` | the carrier said nothing that maps, or there is no carrier to ask |

`isFinal(): bool` is true for `DELIVERED` and `RETURNED`. A carrier's tracking status is the
status of its latest event.

### PickupPoint

A relay, locker or post office a parcel can be sent to and collected from.

| Property | Type | Default | |
|---|---|---|---|
| `carrier` | `string` | - | |
| `id` | `string` | - | the id to put in `Shipment::$pickupPoint` |
| `name` | `string` | - | |
| `address` | `Address` | - | |
| `latitude`, `longitude` | `?float` | `null` | |
| `openingHours` | `array<int, list<array{string, string}>>` | `[]` | ISO weekday (1 = Monday) => list of `[open, close]` slots, `"HH:MM"` |
| `distance` | `?int` | `null` | metres from the address searched, when the carrier says |
