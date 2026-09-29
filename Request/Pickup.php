<?php

namespace Omnibus\Core\Request;

use Omnibus\Core\Model\Address;
use Omnibus\Core\Model\Parcel;
use Omnibus\Core\Model\PickupPoint;

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
