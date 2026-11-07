# Changelog

All notable changes to `slen/sdk` are written here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/), and before 1.0 a minor version may break.

## [Unreleased]

### Added
- `ExecutionCost::$incomplete`: `true` when a provider billed something Slen could not price for one of the run's calls — a usage field it does not know, a price tier, a cache write or a server tool without a price — and `$actual` is then what Slen knew. Set together with `$actual`; `false` until then. The API always sends it, and an answer without it is an `InvalidResponseException`; `new ExecutionCost()` takes it as its third argument.
- On `AttemptSummary`, the attempts of a trace node: `$inputTokens` (the whole input, the cache read and writes included) and `$outputTokens` (the whole paid output, reasoning included); `$cachedInputTokens`, `$cacheWriteTokens`, `$cacheWriteLongTokens` and `$reasoningTokens`, the parts of those; `$toolCalls`, the paid server-tool calls by name (`['web_search' => 6]`); `$costBreakdown`, an `AttemptCostBreakdown` (`Slen\Sdk\Modules\Executions\Data`) — `$input`, `$cachedInput`, `$cacheWrite`, `$output`, `$tools` by name and `$total`, decimal strings — null until the attempt is priced; `$costIncomplete`, the attempt's own `incomplete`. Each is null when the answer leaves it out, as the spec allows.

## [0.4.1] - 2026-11-02

### Added
- `PlanLimitException` (`Slen\Sdk\Exceptions`, a `SlenException`): a refusal of the organization's plan. `plan_limit_reached` (409) — one more would pass what the plan allows — and `plan_feature_unavailable` (403) — the plan does not include the feature — become it; until now they fell back by status to `InvalidRequestException` and `AuthenticationException`. Its `details` carry `required_plan`, the plan to upgrade to. On the Free plan `customers()->create()` of a new customer, `updateAccount()` and a `topUp()` that would open an account are refused so: end customers need a paid plan. No run is refused by a plan.

## [0.4.0] - 2026-10-31

### Breaking
- The types of answers are named as in TypeScript and the API's spec, without `DTO`. No old name is aliased:

  | 0.3 | 0.4.0 |
  |---|---|
  | `Slen\Sdk\Data\HealthDTO` | `Slen\Sdk\Data\Health` |
  | `Slen\Sdk\Data\PageDTO`, `Customers\Data\CustomerPageDTO`, `Customers\Data\CreditEntryPageDTO` | `Slen\Sdk\Data\Page` (`Page<Customer>`, `Page<CreditEntry>`) |
  | `Customers\Data\CustomerDTO`, `CustomerAccountDTO`, `CreditEntryDTO`, `CreditPriceDTO` | `Customer`, `CustomerAccount`, `CreditEntry`, `CreditPrice` |
  | `Operations\Data\ExecutionDTO` | `ExecutionResult` |
  | `ExecutionCostDTO`, `ExecutionCacheDTO`, `WaitingOnDTO`, `ExecutionErrorDTO` | `ExecutionCost`, `ExecutionCache`, `WaitingOn`, `ExecutionError` |
  | `Executions\Data\ExecutionStatusDTO` | `ExecutionStatusView` |
  | `ExecutionTraceDTO`, `TraceNodeDTO`, `TraceEventDTO`, `TraceAttemptDTO` | `ExecutionTrace`, `FlowExecutionNode`, `FlowExecutionEvent`, `AttemptSummary` |
  | `Webhooks\Data\WebhookEventDTO` | `WebhookEvent` |
  | `ExecutionFinishedEventDTO`, `CustomerBalanceLowEventDTO`, `ApprovalRequestedEventDTO`, `ApprovalDecidedEventDTO` | `ExecutionFinishedEvent`, `CustomerBalanceLowEvent`, `ApprovalRequestedEvent`, `ApprovalDecidedEvent` |
  | `Webhooks\Data\UnknownEventDTO` | `UnknownWebhookEvent` |
  | `Observability\SpanDTO` | `Observability\SlenSpan` |

  The namespaces are those of 0.3 (`Customers\Data\…` is `Slen\Sdk\Modules\Customers\Data\…`).
- A page is one `final` `Page` and has no `hasMore()`: a full last page carries a cursor to an empty one, so the end is a page whose `nextCursor` is null — or read every page with `listAll()` / `entriesAll()`.
- `customers()->prices()` answers a `CreditPriceList` (its `data` is the list, `requestId` beside it) instead of an array.

### Added
- `customers()->listAll()` and `customers()->entriesAll()`: every customer, every entry of a statement, read page by page as a `foreach` goes on. They are generators: nothing is sent before the loop starts; the options' `cursor` is where they start, `limit` the size of a page, `timeout` and `retries` hold for each.
- `requestId` on every result read from an answer — `Health`, `ExecutionResult`, `ExecutionStatusView`, `ExecutionTrace`, `Customer`, `CustomerAccount`, `Page`, `CreditPriceList` — from the answer's `X-Request-Id`; a row of a page and an account inside a customer carry none.
- `requestId` in the options of every call that goes over the network: the caller's own id, sent as `X-Request-Id` on every attempt (8 to 128 of `A-Z a-z 0-9 . _ : -`, refused before the wire otherwise; `listAll()` and `entriesAll()` take none). A `ConnectionException` or `TimeoutException` of a call that got no answer carries it as its `requestId`; an answer's own id wins wherever there is one.

### Changed
- Laravel 13 and Symfony HttpClient 8 are supported; the suite runs on them.

## [0.3.2] - 2026-10-31

The same code as 0.3.1, released again because 0.3.1 was withdrawn from Packagist; its changes are listed below.

## [0.3.1] - 2026-10-30 [YANKED]

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

[Unreleased]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.4.1...HEAD
[0.4.1]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.3.2...v0.4.0
[0.3.2]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/Alexey-Shpilka/slen-php/releases/tag/v0.3.0
