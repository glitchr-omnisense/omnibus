<?php

namespace Omnibus\Core\Model;

/** A relay, locker or post office a parcel can be sent to and collected from. */
final readonly class PickupPoint
{
    /**
     * @param array<int, list<array{string, string}>> $openingHours ISO weekday (1 = Monday) => [[open, close], ...], "HH:MM"
     */
    public function __construct(
        public string $carrier,
        public string $id,
        public string $name,
        public Address $address,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public array $openingHours = [],
        /** Metres from the searched address, when the carrier says */
        public ?int $distance = null,
    ) {
    }
}
