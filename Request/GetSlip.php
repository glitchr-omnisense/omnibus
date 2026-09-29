<?php

namespace Omnibus\Request;

use Omnibus\Model\Label;

/** The label of a shipment booked earlier, again (a reprint). Result: the Label. */
final class GetSlip extends Request
{
    public function __construct(public readonly string $trackingNumber, public readonly string $format = Label::PDF)
    {
    }

    public function getLabel(): ?Label
    {
        return $this->getResult();
    }
}
