<?php

namespace Omnibus\Testing;

use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;

/**
 * Addresses and shipments for a carrier's tests: a shop in Paris sending to a
 * customer (in France unless told otherwise). Shipped with the core so a
 * carrier's tests run where the carrier is installed alone.
 */
final class Fixtures
{
    public static function shop(): Address
    {
        return new Address('La Touche Originale', ['12 rue de la Paix'], '75002', 'Paris', 'FR', 'LTO', 'shop@example.org', '+33102030405');
    }

    public static function customer(string $country = 'FR'): Address
    {
        return new Address('Émile Zola', ['21 bis rue de Bruxelles', 'Bâtiment B'], '75009', 'Paris', $country, null, 'emile@example.org', '06 12 34 56 78');
    }

    /** One parcel of 800 g unless weights (grams) are given, reference CMD-1042. */
    public static function shipment(int ...$grams): Shipment
    {
        return new Shipment(self::shop(), self::customer(), array_map(static fn (int $g) => new Parcel($g), $grams ?: [800]), reference: 'CMD-1042');
    }
}
