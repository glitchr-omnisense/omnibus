<?php

namespace Omnibus\Core\Model;

/** One box: its weight (grams), its size (centimetres), what it is worth (minor units). */
final readonly class Parcel
{
    public function __construct(
        public int $weight,
        public ?int $length = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $value = null,
        public string $currency = 'EUR',
        public ?string $reference = null,
    ) {
    }

    /** Longest side plus girth, in centimetres - what size bands are built on. */
    public function girth(): ?int
    {
        if (null === $this->length || null === $this->width || null === $this->height) {
            return null;
        }
        $sides = [$this->length, $this->width, $this->height];
        rsort($sides);

        return $sides[0] + 2 * ($sides[1] + $sides[2]);
    }
}
