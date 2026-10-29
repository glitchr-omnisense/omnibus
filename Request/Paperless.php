<?php

namespace Omnibus\Request;

/**
 * Customs documents sent electronically (paperless trade) for a shipment
 * leaving the customs union. Result: true once the carrier accepted them.
 *
 * Part of the contract (and of the conformance suite) for the carriers that
 * offer it; no carrier package answers it yet: supports(Paperless::class) is
 * false for each of them, and execute() raises RequestNotSupportedException.
 */
final class Paperless extends Request
{
    /** @param array<string, string> $documents type (invoice, proforma...) => PDF content */
    public function __construct(public readonly string $trackingNumber, public readonly array $documents)
    {
    }
}
