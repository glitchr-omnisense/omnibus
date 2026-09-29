<?php

namespace Omnibus\Core\Model;

final readonly class TrackingEvent
{
    public function __construct(
        public \DateTimeImmutable $at,
        public TrackingStatus $status,
        public string $description,
        public ?string $location = null,
        /** The carrier's own code, kept for whoever needs the detail */
        public ?string $code = null,
    ) {
    }
}
