# Slen PHP/Laravel SDK

`slen/sdk` runs your flows and reads how they ended, keeps your end customers and their credits, and checks the webhooks Slen sends you.

- PHP ≥ 8.3. HTTP goes through PSR-18 / PSR-17 (`php-http/discovery`): Guzzle, Symfony HttpClient or any client you already have — the package requires none. The journal is PSR-3.
- Laravel 11 and 12: an auto-discovered service provider, the `Slen` facade and `config/slen-sdk.php`.
- Money and credits are decimal strings, never floats; ids are strings; times are `DateTimeImmutable`; statuses are enums.

## Install

```bash
composer require slen/sdk guzzlehttp/guzzle
```

Any PSR-18 client does. With Guzzle, or with Symfony HttpClient and `nyholm/psr7`, the SDK builds the client itself with the timeouts of its config; a client you pass in is used as it is. Guzzle and Symfony HttpClient 6.2+ take the timeout of a single call as well; an older Symfony HttpClient, like any other client, keeps its own timeouts, and the journal says so when a call asks for one.

## Compatibility

| | Versions | Held by |
|---|---|---|
| PHP | 8.3, 8.4, 8.5 | the suite on 8.4 and 8.5; the package run on 8.3 (`bin/floor.php`) |
| Laravel | 11 and 12 | the provider's tests on `illuminate/*` 11–12; `conflict` refuses an older `illuminate/support` |
| HTTP clients | any PSR-18 client with PSR-17 factories | Guzzle 7.9+ and 8 (the suite runs on both) and Symfony HttpClient 6.2+ take the timeout of a single call; any other client keeps its own timeouts |

## The SDK

```php
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;

$slen = SlenSdk::create(new SlenConfig(apiKey: (string) getenv('SLEN_API_KEY')));
```

`SlenConfig` reads no environment variable: the key is passed. The SDK talks to the hosted API. Building it checks the settings and touches no network — an empty key, a timeout that is not above zero, negative retries are an `InvalidArgumentException` where the SDK is wired, not on its first call. The key and the webhook secret are not public properties and never show in `print_r`, `json_encode` or a stack trace.

| Parameter of `SlenConfig` | Default | |
|---|---|---|
| `apiKey` | — | the project's key, `sl_live_…` |
| `timeout`, `connectTimeout` | `65.0`, `5.0` | seconds, for a client the SDK builds |
| `retries` | `2` | attempts after the first when there is no answer, a 429 or a 503 |
| `logger` | silent | a PSR-3 logger for the journal — below |
| `spanRecorder` | none | where spans go — below |
| `webhookSecret`, `webhookToleranceSeconds` | none, `300` | for checking webhooks — below |
| `httpClient`, `requestFactory`, `streamFactory` | discovered | your PSR-18 client and PSR-17 factories |

`$slen->operations()`, `executions()`, `customers()` and `webhooks()` are the modules below; `$slen->health()` reads the API's liveness.

A call takes what names its subject — the operation and its input, an id, an amount — and then one optional options object: `RequestOptions`, `ExecuteOptions`, `WaitOptions`, `CustomerListOptions`, `AccountOptions`, `BlockOptions`, `MovementOptions`, `EntryListOptions`, `PriceListOptions`, `VerifyOptions`; `WebhooksOptions` is what `WebhooksFacade::standalone()` is built from. The options of every call that goes over the network carry `timeout` (seconds, for each attempt) and `retries` (attempts after the first), which replace the config's for that call alone.

## Run a flow

A flow is run by the code of the operation it is bound to. A flow with a model node reads the conversation from `input.messages`.

```php
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$execution = $slen->operations()->execute(
    'support.reply',
    ['messages' => [['role' => 'user', 'content' => 'Where is my order?']]],
    new ExecuteOptions(customer: 'user_8421', idempotencyKey: 'ticket-42:reply'),
);

if ($execution->isCompleted()) {
    echo $execution->output['content'], PHP_EOL;
}
```

- `$execution->status` is an `ExecutionStatus`: `Queued`, `Waiting`, `Running` (`isPending()`), or `Completed`, `Rejected`, `Failed`, `Uncertain` (`isFinished()`).
- `customer` is the end customer the run is for, and pays in their credits (`context.user_id`); `creditType` names their account (`context.credit_type`). Anything else the flow reads goes in `context`; a `user_id` there that is not `customer` is refused before the wire.
- **The idempotency key is yours to choose**, derived from the unit of work (`ticket-42:reply`). The server runs a key once: a second request under it runs nothing and answers with that run as it is now (`$execution->replayed`). Without a key the SDK makes one up, which covers its own retries and nothing else. For a job of your own queue, `IdempotencyKeys::forJob($jobId, 'support.reply')` gives every attempt of the job the same key.
- **A refusal is an answer or an exception, depending on the flow.** A `reject` node answers with status `Rejected` and its code in `errorCode` (`isRejected()`); a refusal of the platform the flow did not route is thrown (below). `Failed` and `Uncertain` are runs that broke — read `errorCode`, and `errorMessage` to show or log.
- The money of the run's model calls is held and settled inside the flow. `cost->estimated` is known when a model call was authorized; `cost->actual` arrives a minute or two after the run ended, so the answer of a run that has just finished carries `null` there.

`executeAsync()` takes the same arguments and answers at once with status `Queued`, for a run that goes on in a worker.

A run inside an HTTP request should not outlast the request. By default a call may take about three and a half minutes — 65 s an attempt, two repeats and their pauses; one attempt of 20 s at most is:

```php
$execution = $slen->operations()->execute(
    'support.reply',
    ['messages' => [['role' => 'user', 'content' => 'Where is my order?']]],
    new ExecuteOptions(customer: 'user_8421', idempotencyKey: 'ticket-42:reply', timeout: 20.0, retries: 0),
);
```

## Wait for a run

```php
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Executions\Options\WaitOptions;

$queued = $slen->operations()->executeAsync(
    'report.summarize',
    ['messages' => [['role' => 'user', 'content' => 'Summarize the third-quarter report.']]],
    new ExecuteOptions(idempotencyKey: 'report-2026-q3:summary'),
);

// In a queue job or a script, not in an HTTP handler: the wait sleeps in the process.
$finished = $slen->executions()->wait($queued->executionId, new WaitOptions(
    timeoutSeconds: 120,
    onPollError: static function (SlenException $error, int $failuresInARow): void {
        error_log($error->errorCode . ' ' . $failuresInARow);
    },
));
```

`wait()` reads the run until it is over, at the pace the server asks for (`retry_after_seconds`; otherwise 1 s doubling up to 30 s). When the timeout is up it answers the last state it read, which may still be pending — that is not an error. A read that gets no answer, a 429 or a 5xx is told to `onPollError` and the wait goes on; any other failure is thrown at once, and so is what the listener throws. `timeoutSeconds` defaults to 300. Each read is sent once and lasts no longer than the time the wait has left (at least 5 s, at most the config's `timeout`), so a wait ends within `timeoutSeconds` and five seconds where the client takes the timeout of a call.

`$slen->executions()->get($id)` reads the run once; `trace($id)` reads the nodes it walked and its events. Both take `RequestOptions`.

## End customers

A customer is your own user, named by your own opaque id (`externalUserId` — not an email address). Their credits sit in one account per credit type.

```php
use Slen\Sdk\Modules\Customers\Options\MovementOptions;

$slen->customers()->create('user_8421');
$slen->customers()->topUp('user_8421', '100.0000', new MovementOptions(note: 'Invoice 7781', idempotencyKey: 'invoice-7781:topup'));

$customer = $slen->customers()->get('user_8421');
$account = $slen->customers()->charge('user_8421', '5.0000', new MovementOptions(note: 'PDF export', idempotencyKey: 'export-991:charge'));
```

| Call | What it does |
|---|---|
| `list(CustomerListOptions(cursor:, limit:, search:, status:, creditType:, lowBalance:))` | customers with their accounts, a `Page` at a time (`data`, `nextCursor`) |
| `get($id)`, `create($id)` | one customer with every account; `create()` is idempotent and moves no credits |
| `updateAccount($id, $creditType, AccountOptions(allowNegative:, lowBalanceThreshold:))` | the settings of one account; what is left out goes back to `false` and `'0'` |
| `block($id, BlockOptions(reason:))`, `unblock($id)` | a blocked customer's new runs are refused (`CustomerBlockedException`); their balance can still be settled |
| `delete($id)` | a customer with no history; one with history is refused (409) — block them instead |
| `topUp($id, $credits, MovementOptions)`, `charge($id, $credits, MovementOptions)` | add credits; take them for a service of your own application. Above zero |
| `adjust($id, $credits, MovementOptions)` | an operator's correction, with a sign; not zero |
| `entries($id, EntryListOptions(cursor:, limit:, creditType:, entryType:))` | the statement of the customer's accounts, a `Page` at a time |
| `prices(PriceListOptions(operationCode:, creditType:))` | what an operation costs in credits: a `CreditPriceList` (`data`) |

Credits are a decimal string of at most four places (`'5'`, `'5.25'`, `'-3.0000'`); anything else is refused before the wire. `MovementOptions` carries `creditType` (default `'default'`), `note` and `idempotencyKey`; under the same key the server moves the credits once.

## Webhooks

```php
use Slen\Sdk\Modules\Webhooks\Data\ExecutionFinishedEvent;
use Slen\Sdk\Modules\Webhooks\Options\WebhooksOptions;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;

$webhooks = WebhooksFacade::standalone(new WebhooksOptions(secret: (string) getenv('SLEN_WEBHOOK_SECRET')));

$event = $webhooks->constructEvent($request->getContent(), $request->headers->get('Slen-Signature'));

if ($event instanceof ExecutionFinishedEvent) {
    echo $event->executionId, ' ', $event->status->value, PHP_EOL;
}
```

`constructEvent()` checks the `Slen-Signature` header against the raw body — exactly the bytes that arrived — and reads the event. A signature that does not hold is a `WebhookSignatureException` whose `reason` says which check refused it (`missing_header`, `malformed_header`, `outside_tolerance`, `no_matching_signature`): answer 400 and do nothing with the body. A signed body that is not an event is an `InvalidResponseException` (`webhook_payload_invalid`). `verify()` answers `true` or `false` and reads nothing.

`execution.finished`, `customer.balance_low`, `approval.requested` and `approval.decided` are classes of their own; every other event is an `UnknownWebhookEvent` with its `data` as it was sent. A receiver needs the endpoint's signing secret, not an API key: `WebhooksOptions` of `WebhooksFacade::standalone()` carries it, and `$slen->webhooks()` uses the `webhookSecret` of the config. A signature further from now than `webhookToleranceSeconds` (300) is refused. `VerifyOptions` of one call — `secret`, `toleranceSeconds`, `now` — win over both.

## The journal

```php
$slen = SlenSdk::create(new SlenConfig(apiKey: (string) getenv('SLEN_API_KEY'), logger: $logger));

// A copy that writes to another logger, over the same HTTP client; $slen keeps writing to $logger.
$slenOfThisRequest = $slen->withLogger($requestLogger);
```

`logger` is any PSR-3 logger. A `SlenSdk` never changes once it is built: `withLogger()` answers a new one, so a long-running worker shares one SDK and puts the logger of a request on a copy. Each event is a constant of `LogMessages` with a context of snake_case fields — the TypeScript SDK writes the same names with the same fields:

| Event | Level | When |
|---|---|---|
| `slen.request`, `slen.response` | debug | every attempt; the answer, with `status`, `duration_ms` and `request_id` |
| `slen.retry` | warning | an attempt that is repeated: `reason` `no_answer`, `rate_limited` or `unavailable`, `delay_ms` |
| `slen.refused` | info | a 4xx |
| `slen.unauthorized` | error | a 401, or a 403 about the key |
| `slen.failed` | error | a 5xx, an unreadable answer, or no answer after every attempt — with `error_type` |
| `slen.wait.poll`, `slen.wait.poll_failed` | debug, warning | the reads of a wait |
| `slen.webhook.rejected` | warning | a webhook refused, with `reason` |
| `slen.config.timeout_not_applied` | warning | the SDK found a PSR-18 client it cannot give its timeouts to, or a call asked for a timeout its client cannot take |

A request is written by its route — `POST /v1/customers/{externalUserId}/charge`, never the values in it. The journal never carries the API key, the webhook secret, an idempotency key, a body, the server's error message, or a value of the path or the query. A logger that throws does not fail the call.

## Spans

A run leaves one span: `operations.execute`, `operations.execute_async`, or `executions.wait` for a wait. `SlenSpan` has ten fields — the call, `outcome` (`ok`, `refused`, `error`), the run's `status`, `durationMs`, `executionId`, `operationCode`, `estimated`, `actual`, `errorCode`, `cacheHit` — ids, money and a verdict, never a prompt or an answer. Credit movements and webhooks leave none.

```php
use Slen\Sdk\Observability\Psr3SpanRecorder;

$slen = SlenSdk::create(new SlenConfig(
    apiKey: (string) getenv('SLEN_API_KEY'),
    spanRecorder: new Psr3SpanRecorder($logger, 'info'),
));
```

`Psr3SpanRecorder` writes each span as `slen.span` with its fields as context; any `SpanRecorderInterface` of yours can send them to an APM. A recorder that throws does not fail the call.

## Laravel

The provider is discovered; publish the config file if you want to change it:

```bash
php artisan vendor:publish --tag=slen-sdk-config
```

`SLEN_API_KEY` is the one variable you need. The others — `SLEN_TIMEOUT`, `SLEN_CONNECT_TIMEOUT`, `SLEN_RETRIES`, `SLEN_LOG_CHANNEL` (a channel of `config/logging.php` for the journal; else the application's logger), `SLEN_SPANS=log` with `SLEN_SPAN_LEVEL`, `SLEN_WEBHOOK_SECRET`, `SLEN_WEBHOOK_TOLERANCE` — are listed in `config/slen-sdk.php`. `SlenConfig`, the span recorder and `SlenSdk` are singletons of the container; bind `Slen\Sdk\Observability\SpanRecorderInterface` yourself to send spans elsewhere.

```php
use Slen\Sdk\Laravel\Slen;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$execution = Slen::operations()->execute(
    'support.reply',
    $input,
    new ExecuteOptions(customer: (string) $user->id, idempotencyKey: "ticket-{$ticket->id}:reply"),
);
```

The facade and `app(SlenSdk::class)` are the same SDK. It keeps no static state and never changes, so it is safe under Octane.

## Symfony

`SlenSdk` takes no logger after it is built, so autoconfiguration has nothing to call on it: pass the logger in `SlenConfig`.

```yaml
# config/services.yaml
Slen\Sdk\SlenConfig:
    arguments:
        $apiKey: '%env(SLEN_API_KEY)%'
        $logger: '@logger'

Slen\Sdk\SlenSdk:
    factory: ['Slen\Sdk\SlenSdk', 'create']
    arguments: ['@Slen\Sdk\SlenConfig']
```

## Long-running workers (FrankenPHP, Octane, RoadRunner, Swoole)

A worker builds the SDK once and serves many requests with it. Nothing of a `SlenSdk` changes after it is built and nothing in the package is static, so one instance per worker is safe; so is one per request, at the cost of a new HTTP client each time.

```php
// Once per worker.
$slen = SlenSdk::create(new SlenConfig(apiKey: (string) getenv('SLEN_API_KEY'), logger: $logger));

// In each request: the request's logger goes on a copy; the shared SDK writes where it did.
$execution = $slen->withLogger($requestLogger)->operations()->execute(
    'support.reply',
    $input,
    new ExecuteOptions(idempotencyKey: "ticket-{$ticketId}:reply", timeout: 20.0, retries: 0),
);
```

- **The context of a request goes through `withLogger()`**: a copy over the same HTTP client and span recorder. Never change the shared instance.
- **Do not wait in an HTTP handler.** `wait()` sleeps in the process for as long as the run takes. Start the run with `executeAsync()` and learn its end from the `execution.finished` webhook, or wait in a queue job.
- **A run inside a request gets a budget of its own**: `timeout` and `retries` of the call (above), not the config's minutes.
- **Octane**: list `SlenSdk::class` and `SlenConfig::class` in `octane.warm`, so the worker builds them once and keeps its connections alive; after a change of `config/slen-sdk.php` or of the variables, `php artisan octane:reload`.
- **One `SlenSdk` per API key**, built where the key is known; no static cache of SDKs by key.
- **`FakeHttpClient` and `RecordingSpanRecorder` keep everything** they are given: they are for tests, never for a worker.

## Errors

Every failure is a `SlenException` with `errorCode`, `statusCode`, `details`, `requestId` (what support finds the request by) and `retryAfterSeconds`; `type()` is its name in the contract. The code decides the type; a code this version has no name for falls back by status:

| Exception | Codes | Fallback |
|---|---|---|
| `AuthenticationException` | `invalid_api_key`, `insufficient_scope`, `not_authenticated`, `not_a_member`, `account_suspended` | any other 401 / 403 |
| `PolicyDeniedException` | `policy_denied`, `model_not_allowed` | |
| `CustomerBlockedException` (a `PolicyDeniedException`) | `customer_blocked` | |
| `SpendRefusedException` (the family below) | `budget_exceeded`, `operation_limit_exceeded`, `concurrency_limit_exceeded`, `insufficient_credits`, `overrun_stopped`, `overrun_approval_required` | any other 402 |
| `RateLimitException` | `rate_limit_exceeded` | any other 429 |
| `IdempotencyConflictException` | `idempotency_conflict`, `idempotency_in_progress` | |
| `CustomerNotFoundException` | `customer_not_found` | |
| `ProviderException` | `provider_error`, `provider_unavailable`, `provider_timeout`, `provider_rate_limit`, `provider_not_configured`, `pool_exhausted` | any other 5xx |
| `OperationUncertainException` | `operation_uncertain` | |
| `ServiceUnavailableException` | `ledger_unavailable`, `service_unavailable` | |
| `FlowRuntimeException` | `flow_runtime_error`, `unrouted_port`, `step_limit_exceeded`, `http_request_failed`, `unknown_node`, `node_type_not_supported`, `execution_conflict` | |
| `TimeoutException` | `execution_timeout`, `background_hold_expired` | no answer in time (the SDK's own, no code) |
| `ConnectionException` | | Slen could not be reached, or the connection broke (the SDK's own, no code) |
| `InvalidResponseException` | `invalid_response`, `webhook_payload_invalid` (the SDK's own) | |
| `WebhookSignatureException` | `webhook_signature_invalid` (the SDK's own) | |
| `InvalidRequestException` | `cost_calculation_failed`; `invalid_request` (the SDK's own: refused before the wire) | everything else |

They live in `Slen\Sdk\Exceptions`. The same code is the same type in the TypeScript SDK: the table is `contract/error-codes.json` at the root of the repository. Every 402 extends `SpendRefusedException`, so "money said no" is one `catch` that still knows which. A 429 is not in it: `RateLimitException` is the key's own request rate.

**What is retried.** No answer, a 429 and a 503 are sent again, under the same idempotency key, up to `retries` times: a `Retry-After` of up to 30 s is waited out, a longer one comes back to you as `retryAfterSeconds`; otherwise the pause is 200 ms doubling, at most 5 s. A 500, 502 or 504 is never repeated — a provider may have been paid.

**Which of them may have cost money.** The money of a run is the money of the model calls its flow made. The types are drawn along that line, with one exception — the second `ProviderException` row, where the code has to be read as well:

| Exception | Was money spent? | Send it again? |
|---|---|---|
| `SpendRefusedException`, `PolicyDeniedException`, `RateLimitException`, `InvalidRequestException`, `AuthenticationException`, `IdempotencyConflictException` — thrown before the run began | no — nothing ran and nothing was held | only once what was refused has changed |
| `ServiceUnavailableException` | no — Slen did not carry the request out | yes, later, under the same key |
| `TimeoutException`, `ConnectionException` raised by the SDK (`errorCode === null`) | unknown — the request may have reached the server | under the same key only: the SDK has already repeated it so, and the server runs a key once |
| `OperationUncertainException` | **unknown — it may have been.** A provider was called and its answer never came; the hold is left in place until it expires | not under a new key: under the same one the server answers with the run as it ended and runs nothing |
| `ProviderException` with a provider's code — `provider_error`, `provider_unavailable`, `provider_timeout`, `provider_rate_limit`, `provider_not_configured`, `pool_exhausted` | no — for the model call that failed: its hold was given back. A model call the run made before it was paid for (last row) | as the last row says; `provider_not_configured` needs a connection set up first |
| `ProviderException` with any other code, or with none (`errorCode === null`) | **unknown.** A 5xx without a code this version knows lands here too — the page of a proxy that gave up, a bare 500 — and it does not say how far the run got | as for `OperationUncertainException`: not under a new key |
| `FlowRuntimeException` — and **any of the above out of `operations()->execute()`** once the walk has begun: a `ProviderException`, a `SpendRefusedException` or a `PolicyDeniedException` of a later node, `ledger_unavailable`, the server's `execution_timeout` and `background_hold_expired` | whatever model calls the flow made before it stopped were paid for | a repeat under a new key pays for those again. Under the same key the server answers with the run as it ended (`replayed`) and runs nothing — fix the flow or its input, then send it under a new one |

A credit movement (`topUp`, `charge`, `adjust`) moves credits once per key: after a `TimeoutException` or a `ConnectionException`, send it again under the same key.

A success the SDK cannot read — a 2xx whose body is not the API's envelope, such as the page of a proxy — is an `InvalidResponseException` with the status it came with. The call may have gone through; it is not repeated, and the body is never quoted.

## Testing your own code

```php
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Testing\RecordingSpanRecorder;

$http = (new FakeHttpClient())->pushJson(200, ['data' => $answer]);
$spans = new RecordingSpanRecorder();
$slen = SlenSdk::create(new SlenConfig(apiKey: 'key-for-tests', spanRecorder: $spans, httpClient: $http));
```

`FakeHttpClient` is a PSR-18 client that answers from the queue `pushJson()` and `pushException()` fill and keeps every request it was given (`requests()`, `lastRequest()` — PSR-7 requests). `RecordingSpanRecorder` keeps the spans (`spans()`, `last()`). Both are strict: a request with nothing queued, or `last()` with nothing recorded, is a `LogicException`.

## Development

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer install && vendor/bin/phpunit && composer quality'
```

The suite reads `contract/` — the contract both SDKs share, copied here and not edited by hand — and runs the examples of `examples/help`. With `SLEN_OPENAPI_SPEC` naming the API's OpenAPI spec, the contract test and the webhook events are held against it as well; without it those checks are skipped. `AGENTS.md` has the rules of the package.
