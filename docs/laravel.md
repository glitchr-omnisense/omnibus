---
title: Laravel
order: 16
---

## Laravel

omnibridge/laravel, the bridge that hosts omnibase in Laravel, has no Omnibus provider: none is
needed. The registry is plain PHP; a service provider of the application builds it once, from a
config file, with a `symfony/http-client` client (every carrier that calls an API requires that
package; Laravel's own HTTP client is Guzzle's, which the carriers do not take).

```php
// config/omnibus.php
return [
    'gateways' => [
        'relais' => ['factory' => 'mondial_relay', 'options' => [
            'enseigne' => env('MONDIAL_RELAY_ENSEIGNE'),
            'private_key' => env('MONDIAL_RELAY_PRIVATE_KEY'),
        ]],
        'retrait' => ['factory' => 'offline'],
    ],
];
```

```php
// app/Providers/OmnibusServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Omnibus\MondialRelay\MondialRelayGatewayFactory;
use Omnibus\Offline\OfflineGatewayFactory;
use Omnibus\Registry;
use Symfony\Component\HttpClient\HttpClient;

final class OmnibusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function (): Registry {
            $http = HttpClient::create();

            return new Registry([
                new OfflineGatewayFactory(),
                new MondialRelayGatewayFactory($http),
            ], config('omnibus.gateways'));
        });
    }
}
```

Register the provider (`bootstrap/providers.php` on Laravel 11 and later), then inject the
registry where it is needed:

```php
use Omnibus\Registry;

final class CheckoutController
{
    public function rates(Registry $registry)
    {
        $rates = $registry->get('relais')->rate($shipment);
        // ...
    }
}
```

List in the provider every carrier factory the application uses: unlike the Symfony bundle, a
hand-built registry knows only the factories it is given. Configuration caching
(`php artisan config:cache`) keeps working: the options are plain arrays read through `config()`.
This page was written from the registry's API; it was not run in a Laravel application.
