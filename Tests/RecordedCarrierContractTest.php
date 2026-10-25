<?php

namespace Omnibus\Tests;

use Omnibus\Action\ActionInterface;
use Omnibus\Config;
use Omnibus\Exception\CarrierException;
use Omnibus\GatewayFactory;
use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Rate;
use Omnibus\Model\Tracking as TrackingModel;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Rating;
use Omnibus\Request\Request;
use Omnibus\Request\Tracking;
use Omnibus\Testing\GatewayContractTests;
use Omnibus\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite run on a carrier made for it: a JSON API behind a token, rates and tracking. */
final class RecordedCarrierContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new RecordedCarrierFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['key' => 'k'];
    }

    protected function recordedAnswers(): array
    {
        return [
            'POST /token' => ['token' => 't'],
            'GET /rates' => ['rates' => [['code' => 'express', 'cents' => 1290], ['code' => 'eco', 'cents' => 590]]],
            '/parcels/' => fn (string $method, string $url) => ['number' => basename($url), 'events' => [['at' => '2026-10-01T08:00:00+02:00', 'delivered' => false], ['at' => '2026-10-02T10:00:00+02:00', 'delivered' => true]]],
        ];
    }

    protected function sessionRoutes(): array
    {
        return ['POST /token'];
    }

    public function testARequestWithoutARecordedAnswerIsNamed(): void
    {
        $http = new RecordedHttpClient(['POST /token' => ['token' => 't']]);

        try {
            (new RecordedCarrierFactory($http))->create(['key' => 'k'])->track('X1');
            self::fail('tracked without an answer');
        } catch (CarrierException) {
        }
        self::assertSame(['GET https://api.example.test/parcels/X1'], $http->unanswered());
        self::assertCount(2, $http->requests());
    }
}

final class RecordedCarrierFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $http = $this->http;
        $call = static function (string $method, string $path) use ($http): array {
            try {
                $token = $http->request('POST', 'https://api.example.test/token')->toArray()['token'];

                return $http->request($method, 'https://api.example.test'.$path, ['auth_bearer' => $token])->toArray();
            } catch (ExceptionInterface $e) {
                throw new CarrierException('recorded', $e->getMessage(), null, $e);
            }
        };
        $config->defaults([
            'omnibus.factory_name' => 'recorded',
            'omnibus.factory_title' => 'Recorded carrier',
            'omnibus.required_options' => ['key'],
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new class($call) implements ActionInterface {
                public function __construct(private readonly \Closure $call)
                {
                }

                public function supports(Request $request): bool
                {
                    return $request instanceof Rating;
                }

                public function execute(Request $request): void
                {
                    $rates = array_map(static fn (array $r) => new Rate('recorded', $r['code'], ucfirst($r['code']), $r['cents']), ($this->call)('GET', '/rates')['rates']);
                    usort($rates, static fn (Rate $a, Rate $b) => $a->amount <=> $b->amount);
                    $request->setResult($rates);
                }
            },
            'omnibus.action.tracking' => new class($call) implements ActionInterface {
                public function __construct(private readonly \Closure $call)
                {
                }

                public function supports(Request $request): bool
                {
                    return $request instanceof Tracking;
                }

                public function execute(Request $request): void
                {
                    $data = ($this->call)('GET', '/parcels/'.$request->trackingNumber);
                    $events = array_map(static fn (array $e) => new \Omnibus\Model\TrackingEvent(new \DateTimeImmutable($e['at']), $e['delivered'] ? TrackingStatus::DELIVERED : TrackingStatus::IN_TRANSIT, ''), $data['events']);
                    $request->setResult(new TrackingModel('recorded', $data['number'], $events ? $events[array_key_last($events)]->status : TrackingStatus::UNKNOWN, $events));
                }
            },
        ]);
    }
}
