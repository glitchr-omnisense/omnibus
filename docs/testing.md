---
title: Testing
order: 60
---

## Testing

Testing an application that ships with Omnibus. Writing a carrier and its conformance suite:
[Writing a carrier](providers.md).

### The offline gateway as a fake

`omnibus/offline` calls nothing and answers rates, shipments, tracking and pickup points from its
options: in a test, configure the gateway the application uses with the `offline` factory.

```yaml
# config/packages/test/omnibus.yaml
omnibus:
    gateways:
        relais:
            factory: offline
            options:
                rates: [{ service: 24R, label: 'Point Relais', to_pickup_point: true, bands: { 30000: 490 } }]
                tracking_url: 'https://example.org/track/{number}'
                pickup_points: [{ id: FR-066974, name: 'Relais test', street: ['1 rue de test'], postcode: '75009', city: Paris, country: FR }]
```

The gateway keeps the name `relais` for the application; its `getName()` is `offline`. Booking
answers the shipment's `tracking_number` option, else its reference; tracking answers `UNKNOWN`.

### A carrier on recorded answers

To test the application's handling of a real carrier's answers - and of its failures - give the
carrier's factory a `MockHttpClient` (symfony/http-client):

```php
use Omnibus\Exception\CarrierException;
use Omnibus\Testing\Fixtures;
use Omnibus\Ups\UpsGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CheckoutRatesTest extends TestCase
{
    public function testUpsDownShowsNoRate(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new MockResponse('<html>503</html>', ['http_code' => 503]),
        ]);
        $ups = (new UpsGatewayFactory($http))->create(['client_id' => 'id', 'client_secret' => 's', 'account_number' => 'A1B2C3']);

        $this->expectException(CarrierException::class);
        $ups->rate(Fixtures::shipment());
    }
}
```

`Omnibus\Testing\RecordedHttpClient` (shipped with the core, needs symfony/http-client) answers by
route rather than in order: `"METHOD fragment"`, `"fragment"` (matched when the URL contains it) or
`"body:fragment"` (matched on the request body, for SOAP), the first route declared that matches
answering; an array answer is sent as JSON, a string as it is, a closure is called with the method,
URL and options. `requests()` lists what was sent, `unanswered()` the requests no route matched.
`RecordedHttpClient::answer($body, $status, $headers)` and `RecordedHttpClient::networkError()`
build answers; `failing($failure, $kept)` gives the same routes failing, all but `$kept`.

```php
use Omnibus\Testing\RecordedHttpClient;

$http = new RecordedHttpClient([
    'POST /security/v1/oauth/token' => ['access_token' => 'tok', 'expires_in' => 3600],
    '/Shop' => file_get_contents(__DIR__.'/fixtures/ups-shop.json'),
]);
```

### Fixtures

`Omnibus\Testing\Fixtures` gives the addresses and shipments the carriers' own tests use:

| Method | |
|---|---|
| `Fixtures::shop()` | La Touche Originale, 12 rue de la Paix, 75002 Paris, FR, with company, e-mail and phone |
| `Fixtures::customer(string $country = 'FR')` | Émile Zola, 21 bis rue de Bruxelles / Bâtiment B, 75009 Paris, in `$country`, with e-mail and phone |
| `Fixtures::shipment(int ...$grams)` | shop to customer, one parcel of 800 g unless weights are given, reference `CMD-1042` |

### In a Symfony functional test

The registry and the gateways are services: replace a gateway's factory options per environment
(above), or replace the HTTP client the factories receive (`http_client`) with a
`MockHttpClient` in the test environment, as for any Symfony HTTP client test.
