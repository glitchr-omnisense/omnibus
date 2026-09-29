<?php

namespace Omnibus\Request;

use Omnibus\Model\Label;
use Omnibus\Model\Shipment;

/** Book the shipment with the carrier. Result: the Label. */
final class Shipping extends Request
{
    public function __construct(public readonly Shipment $shipment)
    {
    }

    public function getLabel(): ?Label
    {
        return $this->getResult();
    }
}
