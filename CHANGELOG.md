# Changelog

## [Unreleased]

### Added
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
