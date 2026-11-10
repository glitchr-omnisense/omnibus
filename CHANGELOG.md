# Changelog

## [Unreleased]

### Added
- omnisense/omnibus is built on omnisense/contracts, the kernel of the omni families, with usage
  policies; the public API is unchanged. `Config`, `Registry`, `GatewayFactory` and
  `Http\Redaction` are thin subclasses of the kernel's (prefix `omnibus`, omnibus' messages and
  exceptions); `GatewayInterface` extends its `ProviderInterface` (`capabilities()`: the
  operations a gateway supports, `Gateway::OPERATIONS`, and its usage policy),
  `GatewayFactoryInterface` its `ProviderFactoryInterface`, `OmnibusException` its
  `ExceptionInterface`, `InvalidConfigException` its `InvalidConfigException` (a `LogicException`
  as before).
- A carrier declares its usage policy (`omnibus.policy`: its `policy.json`); `Registry::policy()`,
  `policies()`, `guard()` and `guarded()` (the gateway behind its guard, a limit reached refusing
  a request before it is sent), `get()` unguarded as before; `policy` and `usage` beside a
  gateway's options, a shared `RateLimiterInterface` as the registry's third argument; the
  bundle takes `policy`, `usage` and `limiter`. docs/policies.md.
- `Http\Throttle`: a carrier's API client honours the waits it asks for - no call while one
  lasts (`Exception\UsageLimitReachedException`, nothing sent), a 429 a
  `Exception\RateLimitedException` (a `CarrierException`, with `retryAfter`). Nothing retries a
  call.
- `Http\NoRetry`: the HTTP client a factory is given keeps its retries off, so Symfony's
  `RetryableHttpClient` (`framework.http_client`'s `retry_failed`) never sends a label's
  purchase twice.
- The conformance suite checks the usage policy declared, a 429 sent once and holding the next
  calls, and a guarded gateway over a site's limit sending nothing, for every carrier that
  declares a policy.
- `Testing\BareTestCase` and `Tests/LoadAllTest`: every class but the bundle and the test support
  loads with omnisense/contracts and the HTTP client contracts alone. The Symfony bundle is
  marked `@suggested`, and its file names the components it needs when they are missing.
- The harness installs omnisense/contracts from its checkout (`OMNISENSE_CONTRACTS`) or GitHub
  and runs its tests, links each package under `/harness/packages`, runs on the default bridge,
  and runs PHPStan at level max on each package (`phpstan`; `phpstan.neon`,
  `phpstan-baseline.neon`: what was there when PHPStan came in, 83 errors in the core, 93 before
  this change).
- `Http\Redaction`: what the carriers call at their catch of the HTTP client's exception, so
  that a `CarrierException` carries none of the request's secrets - its message with the key's
  parameters, the URL's user part and the request's secrets masked as `***` (`text()`), as
  previous a copy of the client's exception, the same class and code (`copy()`), and no trace
  of the chain keeping its arguments (`withoutArguments()`).
- `Label::$carrierReference`: the carrier's own handle on the booking, for the carriers whose
  later requests need more than the tracking number (Amazon Shipping cancels by its shipment id
  and tracks with the carrier id of the rate bought; SF Express cancels by its order id). Opaque
  and null by default: an application keeps it with the order and passes it to `track()`,
  `slip()` and `cancel()` in place of the tracking number. A trailing optional constructor
  argument: labels built without it are unchanged.

### Changed
- `Request\Paperless`'s docblock says what the manual says: part of the contract and of the
  conformance suite, answered by no carrier package yet.

### Fixed
- `GatewayFactory::create()` throws what fails without arguments in its trace: with
  `zend.exception_ignore_args` off, an `InvalidConfigException` (a missing option) kept the
  options given, credentials included, in the arguments of `create()`.
