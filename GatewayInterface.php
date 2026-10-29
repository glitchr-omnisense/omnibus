<?php

namespace Omnibus;

use Omnibus\Model\Address;
use Omnibus\Model\Label;
use Omnibus\Model\Parcel;
use Omnibus\Model\PickupPoint;
use Omnibus\Model\Rate;
use Omnibus\Model\Shipment;
use Omnibus\Model\Tracking;
use Omnibus\Request\Request;

/**
 * One carrier, configured: the same questions for all of them. Each typed
 * method is a shortcut for execute() with its request.
 */
interface GatewayInterface
{
    public function getName(): string;

    public function getTitle(): string;

    /** @param class-string<Request> $request */
    public function supports(string $request): bool;

    /**
     * @template T of Request
     *
     * @param T $request
     *
     * @return T answered
     *
     * @throws Exception\RequestNotSupportedException
     * @throws Exception\CarrierException
     */
    public function execute(Request $request): Request;

    /** @return Rate[] cheapest first */
    public function rate(Shipment $shipment): array;

    public function ship(Shipment $shipment): Label;

    /** @param string $trackingNumber the label's tracking number, or its carrierReference when it has one */
    public function track(string $trackingNumber, string $locale = 'fr'): Tracking;

    /** @return PickupPoint[] nearest first */
    public function pickupPoints(Address $near, int $limit = 10, ?Parcel $parcel = null): array;

    /** @param string $trackingNumber the label's tracking number, or its carrierReference when it has one */
    public function slip(string $trackingNumber, string $format = Label::PDF): Label;

    /** @param string $trackingNumber the label's tracking number, or its carrierReference when it has one */
    public function cancel(string $trackingNumber): bool;
}
