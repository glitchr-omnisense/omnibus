<?php

namespace Omnibus\Request;

/**
 * Customs documents sent electronically (paperless trade) for a shipment
 * leaving the customs union. Result: true once the carrier accepted them.
 */
final class Paperless extends Request
{
    /** @param array<string, string> $documents type (invoice, proforma...) => PDF content */
    public function __construct(public readonly string $trackingNumber, public readonly array $documents)
    {
    }
}
