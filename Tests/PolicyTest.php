<?php

namespace Omnibus\Tests;

use Omnibus\Action\ActionInterface;
use Omnibus\Bridge\Symfony\OmnibusBundle;
use Omnibus\Config;
use Omnibus\Exception\CarrierException;
use Omnibus\Exception\OmnibusException;
use Omnibus\Exception\RateLimitedException;
use Omnibus\Exception\UsageLimitReachedException;
use Omnibus\GatewayFactory;
use Omnibus\Http\NoRetry;
use Omnibus\Http\Throttle;
use Omnibus\Model\Label;
use Omnibus\Registry;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;
use Omnibus\Testing\Fixtures;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * omnibus on omnisense/contracts' usage policies: a carrier's declared
 * policy and operations, a site's tightening, the guard Registry::guarded()
 * puts in front of a gateway, and what a 429 or a retrying HTTP client does
 * to a label's purchase - sent once, surfaced, the next calls held unsent.
 */
final class PolicyTest extends TestCase
{
    /** @var list<string> the URL of each request that reached the carrier */
    private array $sent = [];

    /** @var list<MockResponse> the answers, in order */
    private array $answers = [];

    public function testAGatewayDeclaresItsPolicyAndItsOperations(): void
    {
        $registry = $this->registry();
        $gateway = $registry->get('labels');

        self::assertSame('policied', $gateway->capabilities()->policy()->provider);
        self::assertSame(['ship'], $gateway->capabilities()->contracts());
        self::assertSame(['labels'], array_keys($registry->policies()));
        self::assertSame(3, $registry->policy('labels')->limitsFor('ship')[0]->max);
    }

    public function testAGatewayWithoutAPolicyHasAnUnknownOne(): void
    {
        $gateway = (new StubFactory())->create(['token' => 't']);

        self::assertFalse($gateway->capabilities()->policy()->knowsLimits());
        self::assertSame('stub', $gateway->capabilities()->policy()->provider);
    }

    public function testAGuardedShipmentOverTheLimitIsNeverSent(): void
    {
        $registry = $this->registry();
        $this->answers = array_fill(0, 3, self::booked());
        $guarded = $registry->guarded('labels');
        for ($i = 1; $i <= 3; ++$i) {
            $guarded->ship(Fixtures::shipment(500));
        }

        try {
            $guarded->ship(Fixtures::shipment(500));
            self::fail('a fourth label within the minute');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnibusException::class, $e, 'one catch holds the family\'s exceptions');
            self::assertGreaterThan(0, $e->retryAfter);
        }
        self::assertCount(3, $this->sent, 'the fourth was not sent');
        self::assertSame($guarded, $registry->guarded('labels'), 'built once');
        self::assertNotSame($guarded, $registry->get('labels'), 'get() gives the gateway unguarded');
    }

    public function testASiteTightensThePolicyButNeverLoosensIt(): void
    {
        $tight = $this->registry(['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['ship']]]]);
        $this->answers = [self::booked()];
        $tight->guarded('labels')->ship(Fixtures::shipment(500));
        try {
            $tight->guarded('labels')->ship(Fixtures::shipment(500));
            self::fail('the site allows one a minute');
        } catch (UsageLimitReachedException) {
        }
        self::assertCount(1, $this->sent);

        $this->expectException(InvalidPolicyException::class);
        $this->registry(['rateLimits' => [['max' => 10, 'window' => 'minute', 'operations' => ['ship']]]])->policy('labels');
    }

    public function testA429ToALabelIsSurfacedNeverRetriedAndHoldsTheNextCalls(): void
    {
        $this->answers = [new MockResponse('{"error":"rate_limited"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']])];
        $gateway = $this->registry()->get('labels');

        try {
            $gateway->ship(Fixtures::shipment(500));
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertInstanceOf(CarrierException::class, $e, 'existing catches still hold it');
            self::assertSame(30, $e->retryAfter);
            self::assertSame('rate_limited', $e->carrierCode);
        }
        self::assertCount(1, $this->sent, 'sent once, not retried');

        try {
            $gateway->ship(Fixtures::shipment(500));
            self::fail('held while the wait lasts');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThan(0, $e->retryAfter);
        }
        self::assertCount(1, $this->sent, 'the held call was not sent');
    }

    /**
     * Symfony's RetryableHttpClient (framework.http_client's retry_failed) retries a 429 or a 503
     * whatever the method: given to a factory, its retries are off, so a label is bought once.
     */
    public function testARetryingHttpClientNeverSendsALabelTwice(): void
    {
        $this->answers = [new MockResponse('{"error":"unavailable"}', ['http_code' => 503]), self::booked()];
        $retrying = new RetryableHttpClient($this->http(), null, 3);
        $gateway = (new PoliciedFactory($retrying))->create();

        try {
            $gateway->ship(Fixtures::shipment(500));
            self::fail('a 503');
        } catch (CarrierException) {
        }
        self::assertCount(1, $this->sent, 'the 503 was not retried by the client');
        self::assertCount(1, $this->answers, 'the second answer was never asked for');
    }

    public function testNoRetryKeepsAClientWithoutRetries(): void
    {
        $plain = new MockHttpClient();

        self::assertSame($plain, NoRetry::of($plain));
    }

    public function testThrottleWithoutABackoffStillRefusesA429(): void
    {
        Throttle::before(null);
        Throttle::after(null, 'policied', 200, []);
        $this->expectException(RateLimitedException::class);
        Throttle::after(null, 'policied', 429, []);
    }

    public function testTheBundleTakesPolicyUsageAndASharedLimiter(): void
    {
        $container = new ContainerBuilder();
        $container->register('http_client', MockHttpClient::class);
        $container->register('app.limiter', InMemoryRateLimiter::class)->setPublic(true);
        $extension = (new OmnibusBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('omnibus', [
            'gateways' => ['retrait' => ['factory' => 'offline', 'policy' => ['rateLimits' => [['max' => 5, 'window' => 'minute']]], 'usage' => ['commercial' => true]]],
            'limiter' => 'app.limiter',
        ]);
        $container->compile();

        $registry = $container->get(Registry::class);
        self::assertInstanceOf(Registry::class, $registry);
        self::assertSame(5, $registry->policy('retrait')->rateLimits[0]->max ?? null, 'the site\'s limit over offline\'s none');
        self::assertSame('offline', $registry->guarded('retrait')->getName());
    }

    /** @param array<string, mixed> $policy */
    private function registry(array $policy = [], ?InMemoryRateLimiter $limiter = null): Registry
    {
        return new Registry([new PoliciedFactory($this->http())], ['labels' => ['factory' => 'policied', 'options' => [], 'policy' => $policy]], $limiter);
    }

    private function http(): HttpClientInterface
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->sent[] = $url;

            return array_shift($this->answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });
    }

    private static function booked(): MockResponse
    {
        return new MockResponse('{"tracking":"1Z999"}');
    }
}

/** A carrier with a policy of its own: 3 labels a minute; its API sends through the factory's client. */
final class PoliciedFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'policied',
            'omnibus.factory_title' => 'Policied',
            'omnibus.policy' => UsagePolicy::fromArray(['provider' => 'policied', 'rateLimits' => [['max' => 3, 'window' => 'minute', 'operations' => ['ship']]]]),
            'omnibus.api' => fn (Config $c) => new PoliciedApi($this->http ?? new MockHttpClient(), $c->backoff()),
            'omnibus.action.shipping' => static fn (Config $c) => new PoliciedShipping(),
        ]);
    }
}

final class PoliciedApi
{
    public function __construct(private readonly HttpClientInterface $http, private readonly ?Backoff $backoff)
    {
    }

    public function book(): string
    {
        Throttle::before($this->backoff);
        $response = $this->http->request('POST', 'https://carrier.example.test/shipments', ['body' => '{}']);
        $status = $response->getStatusCode();
        Throttle::after($this->backoff, 'policied', $status, $response->getHeaders(false));
        if ($status >= 400) {
            throw new CarrierException('policied', \sprintf('HTTP %d', $status));
        }
        $data = json_decode($response->getContent(false), true);

        return \is_array($data) && \is_string($data['tracking'] ?? null) ? $data['tracking'] : '';
    }
}

final class PoliciedShipping implements ActionInterface, \Omnibus\Action\ApiAwareInterface
{
    private ?PoliciedApi $api = null;

    public function setApi(object $api): void
    {
        \assert($api instanceof PoliciedApi);
        $this->api = $api;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping && null !== $this->api);
        $request->setResult(new Label('policied', $this->api->book()));
    }
}
