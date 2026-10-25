---
title: Verified, and not
order: 90
---

# Verified, and not

## What the tests prove

Run on 2026-10-25 in the [harness](harness.md), every package from its checkout, PHP 8.4,
PHPUnit 11.5: 383 tests, 2231 assertions, green; 2 skipped. Each of the 23 carriers runs the
conformance suite (`Omnibus\Testing\GatewayContractTests`, [Writing a carrier](providers.md#conformance-suite))
in `Tests/<Carrier>ContractTest.php`, offline, on recorded answers:

- the factory and the registry, required and operation options;
- each request supported or refused as `supports()` says, an unsupported one sending nothing;
- the models' invariants (carrier names, amounts in minor units, ISO codes, rates cheapest first,
  pickup points nearest first and within the limit, opening hours by ISO weekday as `HH:MM`,
  tracking events oldest first);
- configured rates replacing the rating API;
- every failure of the carrier (HTTP 401, 403, 404, 429, 500, 503, network, unreadable body, login
  refused) a `CarrierException` naming it.

The two skipped tests are the failure check of `omnibus/offline` and `omnibus/colissimo`, which
call no carrier: skipped, not passed.

The core's own tests cover the gateway, the registry, configured rates, the Symfony bundle
(`Tests/Bridge/OmnibusBundleTest.php`) and the plain-PHP run with no framework class loaded
(`Tests/BareTest.php`). The capability table of the [index](index.md#carriers) was probed with
`supports()` on 2026-10-25.

## What is not verified

- That the carriers' APIs answer as recorded. The recorded answers are written from each carrier's
  published documentation; no carrier was called for this run. Mondial Relay and Chronopost publish
  test accounts the harness can call (`docker compose run --rm omnibus track mondial_relay ...`).
- The API shapes of DB Schenker's bookings, Blue Dart, DTDC, SF Express, JD Logistics and ZTO are
  read from documentation only, and flagged "unverified" in their READMEs and manuals.
- Colissimo's web services are not wired: `omnibus/colissimo` answers configured rates only.
- The conformance suite checks the shapes the code returns, not the meaning of each carrier's
  fields: the known defects found while writing these manuals (a status mapping that reads
  "not delivered" as delivered, a pickup point id never sent, a cancellation with the wrong id,
  opening hours not read) are flagged in the carriers' manuals and in the pages above.
- The [Laravel](laravel.md) page was written from the registry's API, not run in a Laravel
  application.

## Responsibility

`omniscient/omnibus` and its `omnibus/*` carriers are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (carrier accounts and contracts, keys, rate limits, how labels and tracking data may be
used), named with a link in each carrier's README. The MIT licence covers this code only and grants
no right to any service or to its data; whoever runs these packages complies with those terms.
