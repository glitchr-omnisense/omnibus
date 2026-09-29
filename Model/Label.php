<?php

namespace Omnibus\Core\Model;

/** The shipping label (or slip) a carrier issued: its tracking number and the printable document. */
final readonly class Label
{
    public const PDF = 'application/pdf';
    public const ZPL = 'application/zpl';

    public function __construct(
        public string $carrier,
        public string $trackingNumber,
        /** The document itself, when the carrier sends it inline */
        public ?string $content = null,
        public string $format = self::PDF,
        /** Or where to download it, when the carrier hands out a link */
        public ?string $url = null,
        public ?string $trackingUrl = null,
    ) {
    }
}
