<?php

namespace Omnibus\Core\Request;

use Omnibus\Core\Model\Rate;
use Omnibus\Core\Model\Shipment;

/** The services that can carry a shipment, and their price. Result: Rate[] */
final class Rating extends Request
{
    public function __construct(public readonly Shipment $shipment)
    {
    }

    /** @return Rate[] */
    public function getRates(): array
    {
        return $this->getResult() ?? [];
    }
}
