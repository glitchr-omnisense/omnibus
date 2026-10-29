---
title: Configuration reference
order: 40
---

# Configuration reference

## The registry's configuration

`new Registry($factories, $config)`, and the bundle's `omnibus.gateways` ([Symfony](symfony.md)),
take the same shape: gateway name => factory and options.

```php
[
    '<gateway name>' => [
        'factory' => '<factory name>',     // required: a factory's getName()
        'options' => [/* ... */],          // optional, default []: handed to the factory's create()
    ],
]
```

The gateway name is the application's (`relais`, `express`, `retrait`); the factory name is the
carrier package's (`mondial_relay`, `ups`, `offline`). Several gateways may use the same factory
with different options (two Chronopost accounts, say).

## The rates option

Every carrier accepts `rates`: a list of services priced from configuration, by weight. Set, it
adds an `Omnibus\Action\ConfiguredRatingAction`, and every carrier that has a rating API leaves
its own out: `rate()` then calls nothing.

```yaml
rates:
    - service: relay                 # required: the code given back as Rate::$service
      label: 'Point relais'          # default: the service
      currency: EUR                  # default: EUR
      days: 4                        # default: null (unknown)
      to_pickup_point: true          # default: false
      countries: [FR, BE]            # default: every country
      bands: { 500: 490, 1000: 590, 5000: 890 }   # up to grams => minor units
```

| Key | Type | Default | |
|---|---|---|---|
| `service` | string | - | required; the service's code, compared with `Shipment::$service` |
| `label` | string | the service | `Rate::$label` |
| `currency` | string | `EUR` | ISO 4217 |
| `days` | int | `null` | `Rate::$days` |
| `to_pickup_point` | bool | `false` | `Rate::$toPickupPoint` |
| `countries` | list of strings | `[]`: every country | the recipient countries served, ISO 3166-1 alpha-2, compared case-insensitively |
| `bands` | map int => int | `[]` | weight in grams (inclusive upper bound) => price in minor units; sorted by weight before use |

How a shipment is priced, for each service in the list:

1. skipped when `countries` is set and the recipient's country is not in it;
2. skipped when `Shipment::$service` is set and is not this service;
3. each parcel takes the price of the first band whose weight it does not exceed; a parcel heavier
   than every band (or a service without bands) skips the service;
4. the parcels' prices are added.

The rates are sorted cheapest first. A service skipped gives no rate; no service left gives `[]`.

## Carriers' options

Each carrier's manual explains its options; the table lists them as each factory declares them
(required options must be set and not empty; the others with their defaults).

| Factory | Required | Optional (default) |
|---|---|---|
| `offline` | - | `tracking_url` (null), `pickup_points` ([]) |
| `mondial_relay` | `enseigne`, `private_key` | `sandbox` (fills the test brand), `endpoint` (`https://api.mondialrelay.com/Web_Services.asmx`), `collection` (`CCC`), `download_labels` (false) |
| `colissimo` | - | - |
| `chronopost` | `account_number`, `password` | `sub_account` (null) |
| `ups` | `client_id`, `client_secret`, `account_number` | `sandbox` (false) |
| `fedex` | `client_id`, `client_secret`, `account_number` | `sandbox` (false) |
| `dhl` | `api_key`, `api_secret`, `account_number` | `sandbox` (false), `location_api_key` (null: no pickup points) |
| `tnt` | `username`, `password`, `account_number` | `sender` (null: the shipment's sender is the collection address) |
| `gls` | `username`, `password`, `contact_id` | `sandbox` (false) |
| `db_schenker` | - | `api_key`, `account_number` (null: tracking only) |
| `usps` | `client_id`, `client_secret` | `sandbox` (false), `crid`, `mid`, `account` (null: no labels) |
| `royal_mail` | `api_key` | `tracking_client_id`, `tracking_client_secret` (null: no tracking) |
| `canada_post` | `username`, `password`, `customer_number` | `contract_id` (null), `sandbox` (false) |
| `purolator` | `key`, `password`, `account_number` | `sandbox` (false) |
| `canpar` | `user_id`, `password`, `shipper_number` | `sandbox` (false) |
| `auspost` | `api_key`, `password`, `account_number` | `sandbox` (false) |
| `aramex` | `username`, `password`, `account_number`, `account_pin`, `account_entity`, `account_country` | `sandbox` (false) |
| `bluedart` | `client_id`, `client_secret`, `login_id`, `licence_key`, `customer_code` | `origin_area` (null), `sandbox` (false) |
| `dtdc` | `api_key`, `customer_code` | `tracking_username`, `tracking_password` (null: no tracking), `sandbox` (false) |
| `sf_express` | `partner_id`, `checkword` | `monthly_card` (null), `sandbox` (false) |
| `jdl_express` | `app_key`, `app_secret`, `access_token`, `customer_code` | `sandbox` (false) |
| `zto_express` | `company_id`, `key` | `partner_code` (null), `sandbox` (false) |
| `amazon` | `client_id`, `client_secret`, `refresh_token` | `region` (`eu`), `business_id` (null), `carrier_id` (`AMZN_UK`: the carrier a bare tracking number is tracked with), `sandbox` (false) |

Besides these, each carrier reads shipment options (`Shipment::$options`: `label_format`,
`description`, `instructions`, Blue Dart's `prices`...): its manual lists every option its code
reads.

## Reserved keys

Options whose name starts with `omnibus.` are the factory's own (`omnibus.factory_name`,
`omnibus.api`, `omnibus.action.*`... [Concepts](concepts.md#factory-and-config)). Given in the
options, they win over the factory's defaults: `omnibus.action.tracking` set to an action of the
application's replaces the carrier's tracking. That is a way to adapt one operation without
writing a carrier; an option set to `null` there removes the action.
