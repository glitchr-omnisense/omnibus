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

/** A carrier's actions behind one door: the first action supporting a request answers it. */
final class Gateway implements GatewayInterface
{
    /** @param ActionInterface[] $actions */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly array $actions,
    ) {
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
