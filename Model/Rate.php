<?php

namespace Omnibus\Core\Model;

/** What a service costs for a shipment (minor units) and how long it takes. */
final readonly class Rate
{
    public function __construct(
        public string $carrier,
        public string $service,
        public string $label,
        public int $amount,
        public string $currency = 'EUR',
        public ?int $days = null,
        public bool $toPickupPoint = false,
    ) {
    }
}
