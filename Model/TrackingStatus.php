<?php

namespace Omnibus\Model;

/** Every carrier's statuses, folded onto one scale. */
enum TrackingStatus: string
{
    case PENDING = 'pending';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case AVAILABLE_FOR_PICKUP = 'available_for_pickup';
    case DELIVERED = 'delivered';
    case EXCEPTION = 'exception';
    case RETURNED = 'returned';
    case UNKNOWN = 'unknown';

    public function isFinal(): bool
    {
        return self::DELIVERED === $this || self::RETURNED === $this;
    }
}
