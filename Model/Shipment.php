<?php

namespace Omnibus\Model;

/**
 * What goes where: from the sender to the recipient - at home, or at a
 * pickup point - in one or more parcels, under a carrier's service.
 */
final readonly class Shipment
{
    /**
     * @param Parcel[]             $parcels
     * @param array<string, mixed> $options carrier-specific (insurance, signature, instructions...)
     */
    public function __construct(
        public Address $sender,
        public Address $recipient,
        public array $parcels,
        public ?string $service = null,
        public ?string $pickupPoint = null,
        public ?string $reference = null,
        public array $options = [],
        public ?\DateTimeImmutable $shippingDate = null,
    ) {
        if (!$parcels) {
            throw new \InvalidArgumentException('A shipment carries at least one parcel.');
        }
    }

    /** Grams, all parcels together. */
    public function weight(): int
    {
        return array_sum(array_map(static fn (Parcel $p) => $p->weight, $this->parcels));
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}
