# Changelog

All notable changes to `slen/sdk` are written here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/), and before 1.0 a minor version may break.

## [Unreleased]

### Added
- `queue_slot`, a value of `WaitingOn::$type`: the run waits for a slot of a Queue node's limit of calls at once. `execute()` answers such a run at once — status `Waiting`, `$retryAfterSeconds` 2 — and the run goes on by itself when a slot is freed; `wait()` follows it as it follows any other wait. `$resumeAt` is the deadline: a run still without a slot then leaves by the node's `busy` port, and with nothing connected there it ends `Rejected` with `$errorCode` `concurrency_limit_exceeded`. `$type` stays a string; its doc comment names the value, and the README shows the wait.

### Changed
- `ConcurrencyLimitException` (`concurrency_limit_exceeded`, 402) is the refusal of a flow's Queue node whose limit of calls at once is full and whose `busy` port leads nowhere — no longer of a `concurrency` budget, a type the API no longer creates. Its `$details` are the node's `busy` output: `reason` (`timeout`, `refused`, `queue_full`), `key`, `limit`, `in_flight`, `waiting`, `waited_seconds`. The doc comment says so; the class and the code it maps are as they were.

## [0.5.0] - 2026-11-08

### Changed
- `FlowExecutionNode::$cost` after the capture is the fact: the estimate the node's hold was taken on until the hold is captured, then what the provider billed — the sum of the call's attempts. `$estimatedCost` keeps the estimate, `$costStatus` says which of the two `$cost` holds (`estimated`, `actual`), and `$costIncomplete` is `true` when the fact misses something the provider billed that Slen cannot price yet. The fact arrives up to a minute after the run ended. The three new fields are null when the answer leaves them out, as the spec allows; the constructor takes them as optional last arguments.

### Added
- `$warnings` on `ExecutionResult`, `ExecutionStatusView` and `ExecutionTrace`: what the run said about its request when it started and went on with — a list of `ExecutionWarning` (`$code`, `$message`, `$keys` — a list of strings or null), empty when there is nothing to say. `input_key_ignored`: the input carried keys of a model call (`tools`, `model`, `temperature`, …, named in `$keys`) that no node of the flow passes to the model; the trace has the event `input_keys_ignored`. The API always sends it, and an answer without it is an `InvalidResponseException`; each constructor takes it as an optional last argument. The SDK writes a list that is not empty to the journal: `slen.execution.warnings` (`LogMessages::EXECUTION_WARNINGS`) at the warning level, one record for each answer — `execute()`, `executeAsync()`, `get()`, `trace()`, and the state a `wait()` answers with — carrying the run's `execution_id` and each warning's `code` and `keys`, never its message.
- `ExecutionCost::$incomplete`: `true` when a provider billed something Slen could not price for one of the run's calls — a usage field it does not know, a price tier, a cache write or a server tool without a price — and `$actual` is then what Slen knew. Set together with `$actual`; `false` until then. The API always sends it, and an answer without it is an `InvalidResponseException`; `new ExecutionCost()` takes it as its third argument.
- On `AttemptSummary`, the attempts of a trace node: `$inputTokens` (the whole input, the cache read and writes included) and `$outputTokens` (the whole paid output, reasoning included); `$cachedInputTokens`, `$cacheWriteTokens`, `$cacheWriteLongTokens` and `$reasoningTokens`, the parts of those; `$toolCalls`, the paid server-tool calls by name (`['web_search' => 6]`); `$costBreakdown`, an `AttemptCostBreakdown` (`Slen\Sdk\Modules\Executions\Data`) — `$input`, `$cachedInput`, `$cacheWrite`, `$output`, `$tools` by name and `$total`, decimal strings — null until the attempt is priced; `$costIncomplete`, the attempt's own `incomplete`. Each is null when the answer leaves it out, as the spec allows.
- On `FlowExecutionNode`, the nodes of a trace: `$output` — what a model node left, its answer on `success` / `tool_calls` (`content`, `finish_reason`, `usage`, `tool_calls`, …) or its `error` on a failure port (`class`, `code`, `http_status`, `message`, `provider`, `model`, `retry_after_seconds`), as an array; `$outputTruncated` — the kept output was cut to the limit (64 KB of JSON by default); `$outputStored` — `stored`, `disabled_by_project` (the project keeps no model answers), `withheld` (grounded with Google Search: its `content` is not kept) or `history_paused`. Null for every other node, and when the answer leaves them out — a trace of a backend that kept no outputs; the constructor takes them as optional last arguments.
- `model_retired` (403) becomes a `PolicyDeniedException`, as `model_not_allowed` does: a model its provider stopped listing, refused before any attempt — nothing was spent; the flow needs another model. Until now the API answered such a call with `model_not_allowed`; without the row the new code would fall back by status to `AuthenticationException`.
- `pool_unavailable` (503) is in the contract copies, unmapped: a model node whose provider keys were all cooling down or refused. It leaves the node by its `unavailable` port; a run that ends on it carries it as its `errorCode`, and as an HTTP error it falls back by status to `ProviderException`, like any 5xx without a row.

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

[Unreleased]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.3.2...v0.4.0
[0.3.2]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/Alexey-Shpilka/slen-php/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/Alexey-Shpilka/slen-php/releases/tag/v0.3.0
