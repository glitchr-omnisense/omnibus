---
title: Writing a carrier
order: 80
---

## Writing a carrier

A carrier is a package `omnibus/<carrier>`, namespace `Omnibus\<Carrier>\`: a factory, an API
client, one action per request it answers, and its tests. `omnibus/ups` is a complete example.

```
ups/
  UpsGatewayFactory.php      # name, title, options, the API client, the actions
  Api.php                    # the carrier's HTTP calls, its errors turned into CarrierException
  Action/RatingAction.php    # one class per request: Rating, Shipping, Tracking, Pickup, GetSlip, Cancel, Paperless
  Tests/UpsGatewayTest.php   # what is particular to the carrier
  Tests/UpsContractTest.php  # the conformance suite
```

### The factory

```php
final class AcmeGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'acme',                       // snake_case, the name gateways are configured with
            'omnibus.factory_title' => 'Acme',
            'omnibus.required_options' => ['api_key'],              // missing: InvalidConfigException naming it
            'tracking_key' => null,
            'omnibus.api' => fn (Config $c) => new Api($this->http ?? HttpClient::create(), (string) $c['api_key']),
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new RatingAction(),
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => static fn (Config $c) => $c['tracking_key'] ? new TrackingAction() : null,
        ]);
    }
}
```

The rules every carrier keeps:

- **Building a gateway calls nothing.** A token is asked for on the first call, not in `create()`.
  The factory takes the application's HTTP client and makes its own when given none.
- **`supports()` is the truth.** A request the carrier does not answer has no action, and the gateway
  raises `RequestNotSupportedException` before any call. An operation that needs an option of its own
  (a second API's credentials, a payer) has no action without that option, rather than an action
  that fails when called: `supports(Tracking::class)` is false until `tracking_key` is set.
- **A `rates` option replaces the rating API.** The carrier's rating action is not registered when
  `rates` is set; `Action\ConfiguredRatingAction` prices from the configuration, with no call.
- **Every failure is a `CarrierException` naming the carrier** (its factory name): an HTTP status of
  400 or more, a network error, a body that is not the carrier's format (an error page from a proxy
  included), a login refused. Catch `Symfony\Contracts\HttpClient\Exception\ExceptionInterface`
  and `\JsonException`, check the status before trusting the body, and put the carrier's own error
  code in `$carrierCode`. A rating action that asks per service may skip a service the carrier
  refuses, but when every call failed it throws the failure: no rate because the carrier failed is
  not "no service".
- **Models keep their invariants**: `carrier` is the factory name; amounts in minor units, ISO 4217
  currencies, ISO 3166-1 alpha-2 countries; rates cheapest first; pickup points nearest first and no
  more than the limit asked, opening hours `"HH:MM"` by ISO weekday; tracking events oldest first;
  `Cancel` and `Paperless` answer `true` or `false`.

### Conformance suite

`Omnibus\Testing\GatewayContractTests` is those rules as PHPUnit tests. A carrier's test case uses
the trait and gives its factory, working options and the carrier's recorded answers; the suite runs
offline, through `Omnibus\Testing\RecordedHttpClient`, and no test reaches the network.

```php
use Omnibus\Testing\GatewayContractTests;

final class AcmeContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new AcmeGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['api_key' => 'test', 'tracking_key' => 'test', 'sandbox' => true];
    }

    protected function recordedAnswers(): array
    {
        return [
            'POST /oauth/token' => ['access_token' => 'tok', 'expires_in' => 3600],    // array: sent as JSON
            '/v1/rates' => file_get_contents(__DIR__.'/recorded/rates.json'),         // string: the body as it is
            '/v1/labels/' => RecordedHttpClient::answer('%PDF-1.4', 200, ['content-type' => 'application/pdf']),
            'body:<TrackRequest' => fn (string $method, string $url, array $options) => '<soap:Envelope>...',
        ];
    }
}
```

A route is `"METHOD fragment"` or `"fragment"`, matched when the URL contains the fragment, or
`"body:fragment"`, matched on the request body (a SOAP operation); the first route declared that
matches answers. A request no route answers fails at the transport and the suite names it.

What it checks, for every carrier:

| Test | |
|---|---|
| `testTheFactoryNamesTheGatewayItBuilds` | the factory's name (snake_case) is the gateway's, a title, no call while building |
| `testTheRegistryBuildsTheGatewayFromItsConfiguration` | `Registry` builds it from `{factory, options}`, once |
| `testEachRequiredOptionIsRequired` | each required option missing is an `InvalidConfigException` naming it; each operation option missing makes its requests unsupported |
| `testRating`, `testShipping`, `testTracking`, `testPickupPoints`, `testSlip`, `testCancellation`, `testPaperless` | supported: answered from the recorded answers, models valid; not supported: `RequestNotSupportedException`, no call |
| `testConfiguredRatesReplaceTheRatingApi` | with `rates`, the configured price and only it, no call |
| `testTheCarriersFailuresAreCarrierExceptions` | for each supported operation that calls the carrier: HTTP 401, 403, 404, 429, 500, 503, a network error, an HTML page with a 200, a login refused - a `CarrierException` naming the carrier, never another exception or an answer; skipped for a gateway that calls no carrier |

Hooks, where a carrier legitimately differs:

| Hook | Default | |
|---|---|---|
| `contractShipment()` | Paris to Paris, 800 g | a shipment the carrier serves (domestic US for USPS...) |
| `contractTrackingNumber()` | `1234567890` | a number the recorded answers know |
| `contractPickupAddress()` | the shipment's recipient | |
| `sessionRoutes()` | none | routes that keep answering while the others fail (a token), so a failure is checked on the call itself; the login refused is checked apart |
| `operationOptions()` | none | option => requests supported only with it |
| `issuesDocuments()` | true | false for a gateway whose label carries no document (offline: the shop prints its own; ZTO: an electronic waybill) |

The recorded answers are written from the carrier's published documentation and, where a sandbox
was reached, from its answers: the suite proves that the carrier's code keeps the contract on
those answers, not that the carrier's API still answers that way (see each carrier's README for
what was verified against the real service).

Requires, in the carrier's `require-dev`: `phpunit/phpunit` and `symfony/http-client` (a carrier
that calls an API requires the latter already). `Omnibus\Testing\Fixtures` gives the addresses and
shipments the carriers' own tests use, shipped with the core so a carrier's tests run where it is
installed alone.
