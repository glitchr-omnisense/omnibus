<?php

namespace Omnibus\Request;

use Omnibus\Model\Tracking as TrackingModel;

/** Where is this parcel? Result: Model\Tracking */
final class Tracking extends Request
{
    public function __construct(public readonly string $trackingNumber, public readonly string $locale = 'fr')
    {
    }

    public function getTracking(): ?TrackingModel
    {
        return $this->getResult();
    }
}
