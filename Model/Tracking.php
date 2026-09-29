<?php

namespace Omnibus\Model;

/** Where a parcel is: its latest status and every step so far, oldest first. */
final readonly class Tracking
{
    /** @param TrackingEvent[] $events */
    public function __construct(
        public string $carrier,
        public string $trackingNumber,
        public TrackingStatus $status,
        public array $events = [],
    ) {
    }

    public function latest(): ?TrackingEvent
    {
        return $this->events ? $this->events[array_key_last($this->events)] : null;
    }
}
