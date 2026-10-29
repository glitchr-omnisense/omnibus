<?php

namespace Omnibus\Model;

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
        /**
         * The carrier's own handle on the booking, for the carriers whose later requests need
         * more than the tracking number (Amazon: the shipment and carrier ids; SF Express: the
         * order id). Opaque: keep it with the order and pass it to track(), slip() and cancel()
         * in place of the tracking number. Null when the tracking number is enough.
         */
        public ?string $carrierReference = null,
    ) {
    }
}
