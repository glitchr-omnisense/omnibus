# Changelog

## [Unreleased]

### Added
- `Label::$carrierReference`: the carrier's own handle on the booking, for the carriers whose
  later requests need more than the tracking number (Amazon Shipping cancels by its shipment id
  and tracks with the carrier id of the rate bought; SF Express cancels by its order id). Opaque
  and null by default: an application keeps it with the order and passes it to `track()`,
  `slip()` and `cancel()` in place of the tracking number. A trailing optional constructor
  argument: labels built without it are unchanged.
