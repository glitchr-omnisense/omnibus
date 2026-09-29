<?php

namespace Omnibus\Core\Action;

use Omnibus\Core\Model\Rate;
use Omnibus\Core\Request\Rating;
use Omnibus\Core\Request\Request;

/**
 * Prices from the gateway's configuration, for carriers that publish no
 * rating API (the price is the shop's contract, not a public tariff).
 *
 *   rates:
 *     - { service: relay, label: 'Point relais', to_pickup_point: true, days: 4,
 *         currency: EUR, countries: [FR, BE],
 *         bands: { 500: 490, 1000: 590, 5000: 890 } }   # up to grams => minor units
 *
 * A band applies up to its weight, each parcel priced on its own; a parcel
 * heavier than the last band, or a country not listed, gets no rate for that
 * service.
 */
final class ConfiguredRatingAction implements ActionInterface
{
    /** @param list<array<string, mixed>> $rates */
    public function __construct(private readonly string $carrier, private readonly array $rates)
    {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Rating;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Rating);
        $shipment = $request->shipment;

        $offered = [];
        foreach ($this->rates as $rate) {
            $countries = array_map('strtoupper', $rate['countries'] ?? []);
            if ($countries && !\in_array(strtoupper($shipment->recipient->country), $countries, true)) {
                continue;
            }
            if ($shipment->service && $shipment->service !== $rate['service']) {
                continue;
            }
            // Each parcel is priced on its own band; one too heavy, no rate.
            $bands = $rate['bands'] ?? [];
            ksort($bands);
            $amount = 0;
            foreach ($shipment->parcels as $parcel) {
                $price = self::band($bands, $parcel->weight);
                if (null === $price) {
                    continue 2;
                }
                $amount += $price;
            }
            $offered[] = new Rate(
                $this->carrier,
                (string) $rate['service'],
                (string) ($rate['label'] ?? $rate['service']),
                $amount,
                (string) ($rate['currency'] ?? 'EUR'),
                isset($rate['days']) ? (int) $rate['days'] : null,
                (bool) ($rate['to_pickup_point'] ?? false),
            );
        }
        usort($offered, static fn (Rate $a, Rate $b) => $a->amount <=> $b->amount);

        $request->setResult($offered);
    }

    /** @param array<int|string, int> $bands sorted by weight */
    private static function band(array $bands, int $weight): ?int
    {
        foreach ($bands as $upTo => $price) {
            if ($weight <= (int) $upTo) {
                return (int) $price;
            }
        }

        return null;
    }
}
