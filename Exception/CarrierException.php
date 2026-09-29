<?php

namespace Omnibus\Core\Exception;

/** The carrier refused, or could not be reached. */
class CarrierException extends \RuntimeException implements OmnibusException
{
    public function __construct(
        public readonly string $carrier,
        string $message,
        /** The carrier's own error code, when it gave one */
        public readonly ?string $carrierCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('[%s] %s', $carrier, $message), 0, $previous);
    }
}
