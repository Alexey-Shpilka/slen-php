# Changelog

All notable changes to `slen/sdk` are written here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/), and before 1.0 a minor version may break.

## [Unreleased]

## [0.3.1] - 2026-10-30

### Fixed
- On Guzzle 8 a call that got no answer — a refused connection, a host that does not resolve, a connect timeout — threw `\Error` (Guzzle 8 has no `getHandlerContext()`), past the retries, the journal and a `catch (SlenException)`. It is a `ConnectionException` again, a timeout a `TimeoutException`, on Guzzle 7 and 8.

### Changed
- Guzzle 7.9+ and 8 are both supported; the suite runs on each.

## [0.3.0] - 2026-10-29

### Breaking
- The public classes left `Slen\Sdk\Shared`: `Slen\Sdk\SlenSdk`, `Slen\Sdk\SlenConfig`, `Slen\Sdk\IdempotencyKeys`, `Slen\Sdk\LogMessages`, `Slen\Sdk\Exceptions\*`, `Slen\Sdk\Data\{HealthDTO,PageDTO}`, `Slen\Sdk\Observability\*`, `Slen\Sdk\Testing\*`; the enums moved to the `Enums` namespace of their module. No old name is aliased.
- A call takes what names its subject, then one optional options object: `RequestOptions`, `ExecuteOptions`, `WaitOptions`, `CustomerListOptions`, `AccountOptions`, `BlockOptions`, `MovementOptions`, `EntryListOptions`, `PriceListOptions`, `VerifyOptions`; `WebhooksFacade::standalone()` takes `WebhooksOptions`. The named arguments of the calls are gone.
- `SlenSdk` is immutable: `setLogger()` is replaced by `withLogger()`, which answers a copy; `SlenSdk` no longer implements `LoggerAwareInterface`.
- The webhook secret of a single check goes in `VerifyOptions`, beside its tolerance and its moment.

### Added
- `timeout` and `retries` of a single call, in the options of every call that goes over the network; Guzzle and Symfony HttpClient 6.2+ take the timeout per call.
- `withLogger()`: a copy of the SDK over the same HTTP client, for the logger of one request.
- A section on long-running workers (FrankenPHP, Octane, RoadRunner, Swoole) and a section on compatibility in the README.

### Fixed
- Each read of `executions()->wait()` lasts no longer than the time the wait has left (at least 5 s), where the client takes a call's timeout; a wait of 10 s could last over a minute.

## [0.2.0]

The first tag; not published.

[Unreleased]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.3.1...HEAD
[0.3.1]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/Alexey-Shpilka/slen-php/releases/tag/v0.3.0
