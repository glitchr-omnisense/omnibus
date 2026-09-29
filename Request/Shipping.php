<?php

namespace Omnibus\Core\Request;

use Omnibus\Core\Model\Label;
use Omnibus\Core\Model\Shipment;

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
