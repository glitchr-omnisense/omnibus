<?php

namespace Omnibus\Request;

use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\PickupPoint;

/** The pickup points near an address (that can take this parcel). Result: PickupPoint[] */
final class Pickup extends Request
{
    public function __construct(
        public readonly Address $near,
        public readonly int $limit = 10,
        public readonly ?Parcel $parcel = null,
    ) {
    }

    /** @return PickupPoint[] */
    public function getPoints(): array
    {
        return $this->getResult() ?? [];
    }
}
