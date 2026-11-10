<?php

namespace Omnibus;

use Omnibus\Action\ActionInterface;
use Omnibus\Exception\CarrierException;
use Omnibus\Exception\RequestNotSupportedException;
use Omnibus\Model\Address;
use Omnibus\Model\Label;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\Tracking as TrackingModel;
use Omnibus\Request;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * A carrier's actions behind one door: the first action supporting a
 * request answers it. The waits the carrier asks for (a 429's Retry-After)
 * hold its calls in every case, in its API client. Guarded (guardedBy(),
 * what Registry::guarded() gives), every request also goes through the
 * carrier's usage policy as the site runs it (omnisense/contracts'
 * PolicyGuard): a limit reached refuses it before anything is sent
 * (UsageLimitReachedException), the call is counted after, answered or
 * failed. Nothing retries a call: a label is bought, a pickup booked, a
 * shipment cancelled once.
 */
final class Gateway implements GatewayInterface, DeclaresUsagePolicyInterface
{
    /** Each request's operation: GatewayInterface's method name, the policy's "operations". */
    public const OPERATIONS = [
        Request\Rating::class => 'rate',
        Request\Shipping::class => 'ship',
        Request\Tracking::class => 'track',
        Request\Pickup::class => 'pickupPoints',
        Request\GetSlip::class => 'slip',
        Request\Cancel::class => 'cancel',
        Request\Paperless::class => 'paperless',
    ];

    private ?Capabilities $capabilities = null;

    /**
     * @param ActionInterface[] $actions
     * @param ?UsagePolicy      $policy  the carrier's, as it declares it (unknown when not given)
     * @param ?PolicyGuard      $guard   the policy every request goes through (none: unguarded)
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly array $actions,
        private readonly ?UsagePolicy $policy = null,
        private readonly ?PolicyGuard $guard = null,
    ) {
    }

    /** The same gateway, its requests through this guard. */
    public function guardedBy(PolicyGuard $guard): self
    {
        return new self($this->name, $this->title, $this->actions, $this->policy, $guard);
    }

    /** The operation a request is: "rate", "ship"... (its class's short name, first letter lower, when it is no request of the family's). */
    public static function operation(Request\Request $request): string
    {
        return self::OPERATIONS[$request::class] ?? lcfirst((new \ReflectionClass($request))->getShortName());
    }

    public function usagePolicy(): UsagePolicy
    {
        return $this->policy ?? UsagePolicy::unknown($this->name);
    }

    public function capabilities(): Capabilities
    {
        if (null === $this->capabilities) {
            $operations = [];
            foreach (self::OPERATIONS as $request => $operation) {
                if ($this->supports($request)) {
                    $operations[] = $operation;
                }
            }
            $this->capabilities = new Capabilities($operations, $this->usagePolicy());
        }

        return $this->capabilities;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function supports(string $request): bool
    {
        $probe = (new \ReflectionClass($request))->newInstanceWithoutConstructor();
        foreach ($this->actions as $action) {
            if ($action->supports($probe)) {
                return true;
            }
        }

        return false;
    }

    public function execute(Request\Request $request): Request\Request
    {
        if (null !== $this->guard) {
            return $this->guard->call(self::operation($request), fn () => $this->answer($request));
        }

        return $this->answer($request);
    }

    /**
     * @template T of Request\Request
     *
     * @param T $request
     *
     * @return T
     */
    private function answer(Request\Request $request): Request\Request
    {
        foreach ($this->actions as $action) {
            if ($action->supports($request)) {
                $action->execute($request);
                if (!$request->isAnswered()) {
                    throw new CarrierException($this->name, \sprintf('%s left the request unanswered.', $action::class));
                }

                return $request;
            }
        }

        throw RequestNotSupportedException::for($request, $this->name);
    }

    public function rate(Shipment $shipment): array
    {
        return $this->execute(new Request\Rating($shipment))->getRates();
    }

    public function ship(Shipment $shipment): Label
    {
        return $this->execute(new Request\Shipping($shipment))->getLabel();
    }

    public function track(string $trackingNumber, string $locale = 'fr'): TrackingModel
    {
        return $this->execute(new Request\Tracking($trackingNumber, $locale))->getTracking();
    }

    public function pickupPoints(Address $near, int $limit = 10, ?Parcel $parcel = null): array
    {
        return $this->execute(new Request\Pickup($near, $limit, $parcel))->getPoints();
    }

    public function slip(string $trackingNumber, string $format = Label::PDF): Label
    {
        return $this->execute(new Request\GetSlip($trackingNumber, $format))->getLabel();
    }

    public function cancel(string $trackingNumber): bool
    {
        return (bool) $this->execute(new Request\Cancel($trackingNumber))->getResult();
    }
}
