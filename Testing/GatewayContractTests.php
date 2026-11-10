<?php

namespace Omnibus\Testing;

use Omnibus\Exception\CarrierException;
use Omnibus\Exception\InvalidConfigException;
use Omnibus\Exception\RateLimitedException;
use Omnibus\Exception\RequestNotSupportedException;
use Omnibus\Exception\UsageLimitReachedException;
use Omnibus\Gateway;
use Omnibus\GatewayFactory;
use Omnibus\GatewayFactoryInterface;
use Omnibus\GatewayInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Label;
use Omnibus\Model\PickupPoint;
use Omnibus\Model\Rate;
use Omnibus\Model\Shipment;
use Omnibus\Model\Tracking as TrackingModel;
use Omnibus\Model\TrackingEvent;
use Omnibus\Model\TrackingStatus;
use Omnibus\Registry;
use Omnibus\Request;
use Omnisense\Contracts\Policy\PolicyExport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What every Omnibus gateway does, as PHPUnit tests a carrier runs against its
 * own factory, offline: the carrier's API is replaced by its recorded answers
 * (RecordedHttpClient), so no test reaches the network.
 *
 * It checks that:
 * - the factory's name is the name of the gateway it builds, and a Registry
 *   builds that gateway from {factory, options}; building it calls nothing;
 * - each required option missing is an InvalidConfigException naming it; an
 *   operation that needs an option of its own (operationOptions()) is not
 *   supported without it, rather than failing when called;
 * - supports() is what the gateway does, for each request: a supported one is
 *   answered with models that keep their invariants (the carrier's name, ids,
 *   amounts in minor units, ISO currencies and countries, rates cheapest first,
 *   pickup points nearest first and no more than asked, tracking events oldest
 *   first, a boolean for Cancel and Paperless), an unsupported one raises
 *   RequestNotSupportedException before any request is sent;
 * - a "rates" option replaces the carrier's rating API: the configured price,
 *   no request;
 * - every failure of the carrier - HTTP 401, 403, 404, 429, 500 and 503, a
 *   network error, a body it cannot read, a login refused - is a
 *   CarrierException naming the carrier, never an HTTP client's exception, a
 *   TypeError, or an answer made of nothing;
 * - the carrier's usage policy is declared as data (policy.json, named in its
 *   composer.json's extra.omnisense.policy) and is the gateway's;
 * - a 429 is a RateLimitedException sent once, never retried, and holds the
 *   carrier's next calls unsent while its Retry-After lasts - so a label is
 *   never bought twice by Omnibus;
 * - a guarded gateway (Registry::guarded()) over the site's limit refuses the
 *   call before anything is sent.
 *
 *   final class UpsContractTest extends TestCase
 *   {
 *       use GatewayContractTests;
 *
 *       protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface { return new UpsGatewayFactory($http); }
 *       protected function gatewayOptions(): array { return ['client_id' => 'id', 'client_secret' => 's', 'account_number' => 'A1B2C3', 'sandbox' => true]; }
 *       protected function recordedAnswers(): array { return ['/oauth/token' => ['access_token' => 't'], '/Shop' => [...], ...]; }
 *       protected function sessionRoutes(): array { return ['/oauth/token']; }
 *   }
 *
 * The hooks below (shipment, tracking number, documents...) have defaults a
 * carrier overrides where it legitimately differs. Requires phpunit/phpunit
 * (the carrier's, in require-dev) and symfony/http-client.
 *
 * @suggested phpunit/phpunit (test support: loaded by a carrier's tests only, with symfony/http-client)
 */
trait GatewayContractTests
{
    /** The factory under test, its gateways calling through $http. */
    abstract protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface;

    /** @return array<string, mixed> options that build a working gateway: test credentials, the sandbox, every option an operation needs */
    abstract protected function gatewayOptions(): array;

    /** @return array<string, mixed> the carrier's recorded answers, route => answer (RecordedHttpClient); [] for a carrier that calls nothing */
    abstract protected function recordedAnswers(): array;

    /** The shipment rated, booked and labelled: one the carrier serves. */
    protected function contractShipment(): Shipment
    {
        return Fixtures::shipment();
    }

    /** A tracking number the recorded answers know (tracking, reprint, cancellation). */
    protected function contractTrackingNumber(): string
    {
        return '1234567890';
    }

    /** Where pickup points are looked for. */
    protected function contractPickupAddress(): Address
    {
        return $this->contractShipment()->recipient;
    }

    /** @return list<string> routes (as in recordedAnswers()) that keep answering while the others fail: a login, a token */
    protected function sessionRoutes(): array
    {
        return [];
    }

    /** Whether a booked shipment comes with its document (content or link); false for a gateway that prints nothing. */
    protected function issuesDocuments(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<class-string<Request\Request>>> option => the requests the gateway supports only with it
     *                                                         (a second API's credentials, a payer): without it, not supported
     */
    protected function operationOptions(): array
    {
        return [];
    }

    // ---- the gateway ---------------------------------------------------------

    public function testTheFactoryNamesTheGatewayItBuilds(): void
    {
        $http = new RecordedHttpClient($this->recordedAnswers());
        $factory = $this->gatewayFactory($http);
        $gateway = $factory->create($this->gatewayOptions());

        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $factory->getName(), 'a factory name is snake_case');
        self::assertSame($factory->getName(), $gateway->getName());
        self::assertNotSame('', trim($gateway->getTitle()), 'a gateway has a title');
        self::assertSame([], $http->requests(), 'building a gateway sends no request');
    }

    public function testTheRegistryBuildsTheGatewayFromItsConfiguration(): void
    {
        $factory = $this->gatewayFactory(new RecordedHttpClient($this->recordedAnswers()));
        $registry = new Registry([$factory], ['contract' => ['factory' => $factory->getName(), 'options' => $this->gatewayOptions()]]);

        self::assertTrue($registry->has('contract'));
        self::assertSame($factory->getName(), $registry->get('contract')->getName());
        self::assertSame($registry->get('contract'), $registry->get('contract'), 'built once');
        self::assertSame($this->gatewayOptions(), $registry->options('contract'));
    }

    public function testEachRequiredOptionIsRequired(): void
    {
        $factory = $this->gatewayFactory(new RecordedHttpClient());
        $required = $factory instanceof GatewayFactory ? (array) $factory->createConfig()->get('omnibus.required_options', []) : [];
        if (!$required) {
            self::assertSame($factory->getName(), $factory->create([])->getName(), 'a gateway that requires no option is built without any');
        }

        foreach ($this->operationOptions() as $option => $requests) {
            foreach ($requests as $request) {
                self::assertTrue($factory->create($this->gatewayOptions())->supports($request), \sprintf('with its option "%s", %s is supported', $option, $request));
            }
            $options = $this->gatewayOptions();
            unset($options[$option]);
            $gateway = $factory->create($options);
            foreach ($requests as $request) {
                self::assertFalse($gateway->supports($request), \sprintf('without its option "%s", %s is not supported', $option, $request));
            }
        }

        foreach ($required as $option) {
            $options = $this->gatewayOptions();
            self::assertArrayHasKey($option, $options, \sprintf('gatewayOptions() gives the required option "%s"', $option));
            unset($options[$option]);
            try {
                $factory->create($options);
                self::fail(\sprintf('a gateway built without its required option "%s"', $option));
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString($option, $e->getMessage());
            }
        }
    }

    // ---- what it supports is what it does ---------------------------------------

    public function testRating(): void
    {
        $this->assertTheOperation(Request\Rating::class);
    }

    public function testShipping(): void
    {
        $this->assertTheOperation(Request\Shipping::class);
    }

    public function testTracking(): void
    {
        $this->assertTheOperation(Request\Tracking::class);
    }

    public function testPickupPoints(): void
    {
        $this->assertTheOperation(Request\Pickup::class);
    }

    public function testSlip(): void
    {
        $this->assertTheOperation(Request\GetSlip::class);
    }

    public function testCancellation(): void
    {
        $this->assertTheOperation(Request\Cancel::class);
    }

    public function testPaperless(): void
    {
        $this->assertTheOperation(Request\Paperless::class);
    }

    public function testConfiguredRatesReplaceTheRatingApi(): void
    {
        $http = new RecordedHttpClient($this->recordedAnswers());
        $gateway = $this->gatewayFactory($http)->create(['rates' => [
            ['service' => 'contract', 'label' => 'Contract rate', 'days' => 3, 'bands' => [100_000 => 990]],
        ]] + $this->gatewayOptions());
        $s = $this->contractShipment();
        $shipment = new Shipment($s->sender, $s->recipient, $s->parcels, null, $s->pickupPoint, $s->reference, $s->options, $s->shippingDate);

        self::assertTrue($gateway->supports(Request\Rating::class));
        $rates = $gateway->rate($shipment);

        self::assertCount(1, $rates, 'the configured rate, and only it');
        self::assertSame([$gateway->getName(), 'contract', 'Contract rate', 990, 'EUR', 3], [$rates[0]->carrier, $rates[0]->service, $rates[0]->label, $rates[0]->amount, $rates[0]->currency, $rates[0]->days]);
        self::assertSame([], $http->requests(), 'configured rates call no API');
    }

    // ---- the carrier's failures ---------------------------------------------

    public function testTheCarriersFailuresAreCarrierExceptions(): void
    {
        $recorded = new RecordedHttpClient($this->recordedAnswers());
        $failures = [];
        foreach ([401, 403, 404, 429, 500, 503] as $status) {
            $failures['HTTP '.$status] = [RecordedHttpClient::answer(['error' => 'recorded failure', 'message' => 'recorded failure', 'status' => $status], $status), $this->sessionRoutes()];
        }
        $failures['a network error'] = [RecordedHttpClient::networkError(), $this->sessionRoutes()];
        $failures['an unreadable body'] = [RecordedHttpClient::answer('<html><body>502 Bad Gateway</body></html>', 200, ['content-type' => 'text/html']), $this->sessionRoutes()];
        if ($this->sessionRoutes()) {
            $failures['the login refused (HTTP 401)'] = [RecordedHttpClient::answer(['error' => 'invalid_client', 'message' => 'recorded failure'], 401), []];
        }

        $checked = 0;
        $violations = [];
        foreach ($this->contractOperations() as $request => $operation) {
            $probe = new RecordedHttpClient($this->recordedAnswers());
            $gateway = $this->gatewayFactory($probe)->create($this->gatewayOptions());
            if (!$gateway->supports($request)) {
                continue;
            }
            try {
                $operation($gateway);
            } catch (CarrierException) {
            }
            if (!$probe->requests()) {
                continue; // answered without calling the carrier: nothing to fail
            }

            foreach ($failures as $what => [$failure, $kept]) {
                ++$checked;
                $gateway = $this->gatewayFactory($recorded->failing($failure, $kept))->create($this->gatewayOptions());
                $short = (new \ReflectionClass($request))->getShortName();
                try {
                    $result = $operation($gateway);
                    $violations[] = \sprintf('%s on %s: answered %s', $short, $what, get_debug_type($result).(\is_array($result) ? '('.\count($result).')' : ''));
                } catch (CarrierException $e) {
                    if ($e->carrier !== $gateway->getName()) {
                        $violations[] = \sprintf('%s on %s: a CarrierException naming "%s", not "%s"', $short, $what, $e->carrier, $gateway->getName());
                    }
                } catch (\Throwable $e) {
                    $violations[] = \sprintf('%s on %s: %s (%s)', $short, $what, $e::class, $e->getMessage());
                }
            }
        }
        if (!$checked) {
            self::markTestSkipped('This gateway calls no carrier: no failure of one to report.');
        }

        self::assertSame([], $violations, 'every failure of the carrier is a CarrierException naming it');
    }

    // ---- the usage policy ----------------------------------------------------

    public function testTheUsagePolicyIsDeclaredAsData(): void
    {
        $factory = $this->gatewayFactory(new RecordedHttpClient($this->recordedAnswers()));
        $package = self::packageOf($factory);
        $declared = null !== $package ? (new PolicyExport())->folders($package)->entries() : [];
        if ([] === $declared) {
            self::markTestSkipped('This factory\'s package declares no policy (extra.omnisense.policy).');
        }

        self::assertCount(1, $declared, 'one policy, named in composer.json\'s extra.omnisense.policy');
        self::assertArrayNotHasKey('error', $declared[0], 'it reads as omnisense/contracts\' schema says: '.json_encode($declared[0]['error'] ?? null));
        $gateway = $factory->create($this->gatewayOptions());
        $policy = $gateway->capabilities()->policy();
        self::assertSame($gateway->getName(), $policy->provider, 'the policy names the gateway\'s carrier');
        $registry = new Registry([$factory], ['carrier' => ['factory' => $factory->getName(), 'options' => $this->gatewayOptions()]]);
        self::assertEquals($policy->forPlan(null), $registry->policy('carrier'), 'the registry runs it as declared, under its default plan');
    }

    public function testA429IsSentOnceNeverRetriedAndHoldsTheNextCalls(): void
    {
        $this->skipUnlessAPolicyIsDeclared();
        $recorded = new RecordedHttpClient($this->recordedAnswers());
        $tooMany = RecordedHttpClient::answer(['error' => 'recorded 429', 'message' => 'recorded 429'], 429, ['retry-after' => '30']);
        $checked = 0;
        $violations = [];
        foreach ($this->contractOperations() as $request => $operation) {
            $probe = new RecordedHttpClient($this->recordedAnswers());
            $gateway = $this->gatewayFactory($probe)->create($this->gatewayOptions());
            if (!$gateway->supports($request)) {
                continue;
            }
            try {
                $operation($gateway);
            } catch (CarrierException) {
            }
            if (!$probe->requests()) {
                continue; // answered without calling the carrier
            }
            ++$checked;
            $short = (new \ReflectionClass($request))->getShortName();
            $http = $recorded->failing($tooMany, $this->sessionRouteList());
            $gateway = $this->gatewayFactory($http)->create($this->gatewayOptions());
            try {
                $operation($gateway);
                $violations[] = $short.': answered a 429';
                continue;
            } catch (RateLimitedException $e) {
                if (30 !== $e->retryAfter || $e->carrier !== $gateway->getName()) {
                    $violations[] = \sprintf('%s: a RateLimitedException of "%s" waiting %s, not 30 s', $short, $e->carrier, var_export($e->retryAfter, true));
                }
            } catch (UsageLimitReachedException) {
                // An operation that goes on after a refused call (a rating per service) is held at its next one.
            } catch (\Throwable $e) {
                $violations[] = \sprintf('%s: %s (%s), not a RateLimitedException', $short, $e::class, $e->getMessage());
                continue;
            }
            $refused = array_values(array_filter($http->requests(), fn (array $r): bool => !$this->isSessionRoute($r)));
            if (1 !== \count($refused)) {
                $violations[] = \sprintf('%s: %d requests sent to the carrier, not one (nothing retried)', $short, \count($refused));
            }
            $sent = \count($http->requests());
            try {
                $operation($gateway);
                $violations[] = $short.': called again while the wait lasts';
            } catch (UsageLimitReachedException $e) {
                if (null === $e->retryAfter || $e->retryAfter <= 0) {
                    $violations[] = $short.': held without saying how long';
                }
            } catch (\Throwable $e) {
                $violations[] = \sprintf('%s held: %s (%s), not a UsageLimitReachedException', $short, $e::class, $e->getMessage());
            }
            if (\count($http->requests()) !== $sent) {
                $violations[] = $short.': a request went out while the wait lasted';
            }
        }
        if (!$checked) {
            self::markTestSkipped('This gateway calls no carrier: no 429 to honour.');
        }

        self::assertSame([], $violations, 'a 429 is surfaced once and holds the carrier\'s next calls unsent');
    }

    public function testAGuardedGatewayOverTheSitesLimitSendsNothing(): void
    {
        $this->skipUnlessAPolicyIsDeclared();
        foreach ($this->contractOperations() as $request => $operation) {
            $probe = new RecordedHttpClient($this->recordedAnswers());
            $gateway = $this->gatewayFactory($probe)->create($this->gatewayOptions());
            if (!$gateway->supports($request)) {
                continue;
            }
            try {
                $operation($gateway);
            } catch (CarrierException) {
                continue;
            }
            if (!$probe->requests()) {
                continue;
            }
            $http = new RecordedHttpClient($this->recordedAnswers());
            $factory = $this->gatewayFactory($http);
            $name = Gateway::OPERATIONS[$request];
            $registry = new Registry([$factory], ['carrier' => [
                'factory' => $factory->getName(),
                'options' => $this->gatewayOptions(),
                'policy' => ['rateLimits' => [['max' => 1, 'window' => 'hour', 'operations' => [$name]]]],
            ]]);
            $guarded = $registry->guarded('carrier');
            $operation($guarded);
            $sent = \count($http->requests());
            try {
                $operation($guarded);
                self::fail(\sprintf('a second %s within the hour the site allows one', $name));
            } catch (UsageLimitReachedException $e) {
                self::assertGreaterThan(0, $e->retryAfter);
            }
            self::assertCount($sent, $http->requests(), 'refused before anything was sent');
            self::assertNotSame($guarded, $registry->get('carrier'), 'get() gives the gateway unguarded');

            return;
        }

        self::markTestSkipped('This gateway calls no carrier: no limit to count.');
    }

    // ---- the machinery ------------------------------------------------------

    /** @return array<class-string<Request\Request>, \Closure(GatewayInterface): mixed> */
    private function contractOperations(): array
    {
        return [
            Request\Rating::class => fn (GatewayInterface $g) => $g->rate($this->contractShipment()),
            Request\Shipping::class => fn (GatewayInterface $g) => $g->ship($this->contractShipment()),
            Request\Tracking::class => fn (GatewayInterface $g) => $g->track($this->contractTrackingNumber()),
            Request\Pickup::class => fn (GatewayInterface $g) => $g->pickupPoints($this->contractPickupAddress(), 2),
            Request\GetSlip::class => fn (GatewayInterface $g) => $g->slip($this->contractTrackingNumber()),
            Request\Cancel::class => fn (GatewayInterface $g) => $g->execute(new Request\Cancel($this->contractTrackingNumber()))->getResult(),
            Request\Paperless::class => fn (GatewayInterface $g) => $g->execute(new Request\Paperless($this->contractTrackingNumber(), ['invoice' => "%PDF-1.4\n%recorded"]))->getResult(),
        ];
    }

    /** @param class-string<Request\Request> $request */
    private function assertTheOperation(string $request): void
    {
        $http = new RecordedHttpClient($this->recordedAnswers());
        $gateway = $this->gatewayFactory($http)->create($this->gatewayOptions());
        $operation = $this->contractOperations()[$request];
        $short = (new \ReflectionClass($request))->getShortName();

        if (!$gateway->supports($request)) {
            try {
                $operation($gateway);
                self::fail(\sprintf('%s answered though supports() says no', $short));
            } catch (RequestNotSupportedException $e) {
                self::assertStringContainsString($gateway->getName(), $e->getMessage());
            }
            self::assertSame([], $http->requests(), \sprintf('an unsupported %s sends no request', $short));

            return;
        }

        try {
            $result = $operation($gateway);
        } catch (CarrierException $e) {
            if ($http->unanswered()) {
                self::fail(\sprintf("%s needs a recorded answer for:\n%s", $short, implode("\n", $http->unanswered())));
            }
            throw $e;
        }
        self::assertSame([], $http->unanswered(), 'every request has its recorded answer');

        $carrier = $gateway->getName();
        match ($request) {
            Request\Rating::class => $this->assertRates($carrier, $result),
            Request\Shipping::class, Request\GetSlip::class => $this->assertLabel($carrier, $result, Request\GetSlip::class === $request ? $this->contractTrackingNumber() : null),
            Request\Tracking::class => $this->assertTracking($carrier, $result),
            Request\Pickup::class => $this->assertPickupPoints($carrier, $result, 2),
            default => self::assertIsBool($result, \sprintf('%s answers true or false', $short)),
        };
    }

    private function assertRates(string $carrier, mixed $rates): void
    {
        self::assertIsList($rates);
        $previous = \PHP_INT_MIN;
        foreach ($rates as $rate) {
            self::assertInstanceOf(Rate::class, $rate);
            self::assertSame($carrier, $rate->carrier);
            self::assertNotSame('', trim($rate->service), 'a rate names its service');
            self::assertNotSame('', trim($rate->label), 'a rate has a label');
            self::assertGreaterThanOrEqual(0, $rate->amount, 'an amount in minor units, not negative');
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $rate->currency, 'an ISO 4217 currency');
            self::assertTrue(null === $rate->days || $rate->days >= 0, 'days in transit, not negative');
            self::assertGreaterThanOrEqual($previous, $rate->amount, 'rates come cheapest first');
            $previous = $rate->amount;
        }
    }

    private function assertLabel(string $carrier, mixed $label, ?string $trackingNumber): void
    {
        self::assertInstanceOf(Label::class, $label);
        self::assertSame($carrier, $label->carrier);
        self::assertNotSame('', trim($label->trackingNumber), 'a label carries its tracking number');
        if (null !== $trackingNumber) {
            self::assertSame($trackingNumber, $label->trackingNumber, 'a reprint is the shipment asked for');
        }
        self::assertMatchesRegularExpression('#^[a-z]+/[\w.+-]+$#', $label->format, 'the document\'s media type');
        if ($this->issuesDocuments()) {
            self::assertTrue(('' !== (string) $label->content) || null !== $label->url, 'a label comes with its document, inline or as a link');
        }
        foreach (['url' => $label->url, 'trackingUrl' => $label->trackingUrl] as $field => $url) {
            self::assertTrue(null === $url || false !== filter_var($url, \FILTER_VALIDATE_URL), \sprintf('the label\'s %s is an address', $field));
        }
    }

    private function assertTracking(string $carrier, mixed $tracking): void
    {
        self::assertInstanceOf(TrackingModel::class, $tracking);
        self::assertSame($carrier, $tracking->carrier);
        self::assertSame($this->contractTrackingNumber(), $tracking->trackingNumber, 'the parcel asked about');
        self::assertInstanceOf(TrackingStatus::class, $tracking->status);
        $previous = null;
        foreach ($tracking->events as $event) {
            self::assertInstanceOf(TrackingEvent::class, $event);
            self::assertInstanceOf(TrackingStatus::class, $event->status);
            if (null !== $previous) {
                self::assertGreaterThanOrEqual($previous, $event->at, 'tracking events come oldest first');
            }
            $previous = $event->at;
        }
    }

    private function assertPickupPoints(string $carrier, mixed $points, int $limit): void
    {
        self::assertIsList($points);
        self::assertLessThanOrEqual($limit, \count($points), 'no more pickup points than asked for');
        $previous = null;
        foreach ($points as $point) {
            self::assertInstanceOf(PickupPoint::class, $point);
            self::assertSame($carrier, $point->carrier);
            self::assertNotSame('', trim($point->id), 'a pickup point has its id');
            self::assertNotSame('', trim($point->name), 'a pickup point has a name');
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $point->address->country, 'an ISO 3166-1 alpha-2 country');
            self::assertTrue(null === $point->latitude || abs($point->latitude) <= 90, 'a latitude');
            self::assertTrue(null === $point->longitude || abs($point->longitude) <= 180, 'a longitude');
            foreach ($point->openingHours as $day => $slots) {
                self::assertContains($day, range(1, 7), 'opening hours by ISO weekday');
                foreach ($slots as $slot) {
                    self::assertMatchesRegularExpression('/^\d\d:\d\d$/', $slot[0] ?? '', 'opening at HH:MM');
                    self::assertMatchesRegularExpression('/^\d\d:\d\d$/', $slot[1] ?? '', 'closing at HH:MM');
                }
            }
            if (null !== $point->distance) {
                self::assertGreaterThanOrEqual(0, $point->distance);
                self::assertGreaterThanOrEqual($previous ?? 0, $point->distance, 'pickup points come nearest first');
                $previous = $point->distance;
            }
        }
    }

    /**
     * Whether a request is one of sessionRoutes() (a login, a token): "METHOD fragment" or
     * "fragment", the fragment in its URL or, as "body:fragment", in its body.
     *
     * @param array{method: string, url: string, options: array<string, mixed>} $request
     */
    private function isSessionRoute(array $request): bool
    {
        $body = \is_string($request['options']['body'] ?? null) ? $request['options']['body'] : '';
        foreach ($this->sessionRouteList() as $route) {
            $verb = null;
            $fragment = $route;
            if (preg_match('~^([A-Z]+) (.+)$~', $route, $m)) {
                [, $verb, $fragment] = $m;
            }
            $matches = str_starts_with($fragment, 'body:') ? str_contains($body, substr($fragment, 5)) : str_contains($request['url'], $fragment);
            if ((null === $verb || $verb === strtoupper($request['method'])) && $matches) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> sessionRoutes(), as a list of texts */
    private function sessionRouteList(): array
    {
        return array_values(array_filter($this->sessionRoutes(), 'is_string'));
    }

    private function skipUnlessAPolicyIsDeclared(): void
    {
        $package = self::packageOf($this->gatewayFactory(new RecordedHttpClient($this->recordedAnswers())));
        if (null === $package || [] === (new PolicyExport())->folders($package)->entries()) {
            self::markTestSkipped('This factory\'s package declares no usage policy: its API client is not on omnisense/contracts yet.');
        }
    }

    /** The folder of the package the factory's class belongs to: the nearest composer.json above it. */
    private static function packageOf(object $factory): ?string
    {
        $dir = \dirname((string) (new \ReflectionClass($factory))->getFileName());
        for ($i = 0; $i < 4 && \strlen($dir) > 1; ++$i, $dir = \dirname($dir)) {
            if (is_file($dir.'/composer.json')) {
                return $dir;
            }
        }

        return null;
    }
}
