<?php

namespace Omnibus\Model;

/** A postal address: the sender's, the recipient's, a pickup point's. */
final readonly class Address
{
    /** @param string[] $street one line each, most carriers take up to three */
    public function __construct(
        public string $name,
        public array $street,
        public string $postcode,
        public string $city,
        /** ISO 3166-1 alpha-2 */
        public string $country,
        public ?string $company = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {
    }

    public function line(int $index): string
    {
        return $this->street[$index] ?? '';
    }
}
