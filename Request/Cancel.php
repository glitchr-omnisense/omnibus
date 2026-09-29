<?php

namespace Omnibus\Request;

/** Cancel a shipment not handed over yet. Result: true when the carrier voided it. */
final class Cancel extends Request
{
    public function __construct(public readonly string $trackingNumber)
    {
    }
}
