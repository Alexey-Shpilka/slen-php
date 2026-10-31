<?php

declare(strict_types=1);

// The floor of the package, run on the lowest PHP composer.json declares: the SDK does its work on the doubles,
// without PHPUnit, whose suite is written for 8.4. A failed check names itself and exits 1.

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\WebhookSignatureException;
use Slen\Sdk\Modules\Customers\Options\EntryListOptions;
use Slen\Sdk\Modules\Customers\Options\MovementOptions;
use Slen\Sdk\Modules\Executions\Options\WaitOptions;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;
use Slen\Sdk\Modules\Webhooks\Options\VerifyOptions;
use Slen\Sdk\Modules\Webhooks\Options\WebhooksOptions;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\Client\HttpClientFactory;
use Slen\Sdk\Shared\Http\Transport\GuzzleTransport;
use Slen\Sdk\Shared\Http\Transport\SymfonyTransport;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Tests\Paths;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;

require __DIR__ . '/../vendor/autoload.php';

$holds = static function (bool $holds, string $what): void {
    if ($holds === false) {
        throw new RuntimeException($what);
    }
};

$sdk = static function (FakeHttpClient $http, int $retries = 0): SlenSdk {
    return SlenSdk::create((new SlenConfig('sl_live_floor', retries: $retries))->withHttpClient($http));
};

$checks = [
    'a run with its options' => static function () use ($holds, $sdk): void {
        $http = (new FakeHttpClient())->pushJson(200, ['data' => Answers::execution()]);

        $execution = $sdk($http)->operations()->execute(
            'support.reply',
            ['messages' => [['role' => 'user', 'content' => 'Where is my order?']]],
            new ExecuteOptions(customer: 'user_8421', idempotencyKey: 'floor-1:reply', timeout: 20.0),
        );

        $holds($execution->isCompleted() === true, 'the run is not completed');
        $holds($http->lastRequest()->getHeaderLine('Idempotency-Key') === 'floor-1:reply', 'the key of the run was not sent');
        $holds(str_contains((string) $http->lastRequest()->getBody(), '"user_id":"user_8421"'), 'the customer was not sent');
    },
    'a run on a worker, then its wait' => static function () use ($holds, $sdk): void {
        $http = (new FakeHttpClient())
            ->pushJson(202, ['data' => Answers::pending('queued', 1, ['replayed' => false, 'error' => null])])
            ->pushJson(200, ['data' => Answers::status()]);
        $slen = $sdk($http);

        $queued = $slen->operations()->executeAsync('support.reply', ['messages' => []], new ExecuteOptions(idempotencyKey: 'floor-2:reply'));
        $finished = $slen->executions()->wait($queued->executionId, new WaitOptions(timeoutSeconds: 10));

        $holds($queued->isPending() === true && $finished->isCompleted() === true, 'the wait did not end with the run');
        $holds(\count($http->requests()) === 2, 'the wait read the run more than once');
    },
    'customers: a page, one, a top-up, a charge, a statement, the prices' => static function () use ($holds, $sdk): void {
        $http = (new FakeHttpClient())
            ->pushJson(200, CustomerAnswers::page([CustomerAnswers::customer()]))
            ->pushJson(200, ['data' => CustomerAnswers::customer()])
            ->pushJson(200, ['data' => CustomerAnswers::account()])
            ->pushJson(200, ['data' => CustomerAnswers::account()])
            ->pushJson(200, CustomerAnswers::page([CustomerAnswers::entry()]))
            ->pushJson(200, ['data' => [CustomerAnswers::price()]]);
        $customers = $sdk($http)->customers();

        $holds(\count($customers->list()->data) === 1, 'the page of customers was not read');
        $holds($customers->get('user_8421')->externalUserId === 'user_8421', 'the customer was not read');
        $customers->topUp('user_8421', '100.0000', new MovementOptions(note: 'Invoice 7781', idempotencyKey: 'invoice-7781:topup'));
        $customers->charge('user_8421', '5.0000', new MovementOptions(idempotencyKey: 'export-991:charge'));
        $holds(\count($customers->entries('user_8421', new EntryListOptions(limit: 2))->data) === 1, 'the statement was not read');
        $holds(\count($customers->prices()->data) === 1, 'the prices were not read');
        $holds(\count($http->requests()) === 6, 'the customers did not make one request each');
    },
    'a repeat after a 503' => static function () use ($holds, $sdk): void {
        $http = (new FakeHttpClient())
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0'])
            ->pushJson(200, ['data' => CustomerAnswers::customer()]);

        $sdk($http, 1)->customers()->get('user_8421');

        $holds(\count($http->requests()) === 2, 'the 503 was not repeated');
    },
    'a 402 is its exception' => static function () use ($holds, $sdk): void {
        $http = (new FakeHttpClient())->pushJson(402, Answers::refusal('insufficient_credits'));

        try {
            $sdk($http)->customers()->charge('user_8421', '5', new MovementOptions(idempotencyKey: 'export-992:charge'));
        } catch (InsufficientCreditsException $insufficientCreditsException) {
            $holds($insufficientCreditsException->errorCode === 'insufficient_credits', 'the refusal lost its code');

            return;
        }

        $holds(false, 'the 402 was not thrown');
    },
    'a copy with another logger' => static function () use ($holds): void {
        $logger = new class () extends AbstractLogger {
            public int $records = 0;

            public function log($level, string|Stringable $message, array $context = []): void
            {
                ++$this->records;
            }
        };
        $http = (new FakeHttpClient())->pushJson(200, ['data' => CustomerAnswers::customer()]);
        $slen = SlenSdk::create((new SlenConfig('sl_live_floor', retries: 0))->withHttpClient($http));

        $slen->withLogger($logger)->customers()->get('user_8421');

        $holds($logger->records === 2, 'the copy did not write to its logger');
        $holds($slen->config()->logger instanceof NullLogger, 'the copy changed the SDK it was made from');
    },
    'the clients the SDK builds itself' => static function () use ($holds): void {
        $journal = new Journal(new NullLogger());
        $withoutGuzzle = static function (string $class): bool {
            return $class !== GuzzleClient::class && class_exists($class);
        };

        $holds((new HttpClientFactory())->create(new SlenConfig('sl_live_floor'), $journal)->transport instanceof GuzzleTransport, 'Guzzle was not built');
        $holds((new HttpClientFactory($withoutGuzzle))->create(new SlenConfig('sl_live_floor'), $journal)->transport instanceof SymfonyTransport, 'Symfony was not built');
    },
    'the timeout of a call reaches Guzzle' => static function () use ($holds): void {
        $history = new ArrayObject();
        $container = $history;
        $handlerStack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['data' => CustomerAnswers::customer()], JSON_THROW_ON_ERROR))]));
        $handlerStack->push(Middleware::history($container));
        $slen = SlenSdk::create(new SlenConfig('sl_live_floor', httpClient: new GuzzleClient(['handler' => $handlerStack])));

        $slen->customers()->get('user_8421', new RequestOptions(timeout: 3.5));

        $sent = $history[0] ?? [];
        $holds(\is_array($sent) === true && ($sent['options']['timeout'] ?? null) === 3.5, 'Guzzle was not given the timeout of the call');
    },
    'a closed port of the loopback is a ConnectionException, on the Guzzle the SDK builds' => static function () use ($holds): void {
        // A port nobody listens on: bound for a free number, then closed.
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $holds($listener !== false, 'no port of the loopback could be bound');
        $address = (string) stream_socket_get_name($listener, false);
        fclose($listener);
        $slen = SlenSdk::create(new SlenConfig('sl_live_floor', baseUrl: 'http://' . $address, timeout: 2.0, connectTimeout: 2.0, retries: 0));

        try {
            $slen->customers()->get('user_8421');
        } catch (ConnectionException $connectionException) {
            $holds($connectionException->getPrevious() instanceof ConnectException, 'the refusal did not come from Guzzle');

            return;
        }

        $holds(false, 'the closed port answered');
    },
    'every webhook vector of the contract' => static function () use ($holds): void {
        /** @var array{secret: string, tolerance_seconds: int, cases: list<array{name: string, payload: string, header: ?string, now: int, valid: bool, reason: ?string, event: ?array{id: string, type: string}}>} $vectors */
        $vectors = json_decode((string) file_get_contents(Paths::CONTRACT . '/webhook-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        $receiver = WebhooksFacade::standalone(new WebhooksOptions(secret: $vectors['secret'], toleranceSeconds: $vectors['tolerance_seconds']));

        foreach ($vectors['cases'] as $case) {
            $atTheVector = new VerifyOptions(now: new DateTimeImmutable('@' . $case['now']));

            try {
                $event = $receiver->constructEvent($case['payload'], $case['header'], $atTheVector);
                $holds($case['event'] === ['id' => $event->id, 'type' => $event->type], $case['name']);
            } catch (WebhookSignatureException $webhookSignatureException) {
                $holds($case['valid'] === false && $webhookSignatureException->reason === $case['reason'], $case['name']);
            } catch (InvalidResponseException) {
                $holds($case['valid'] === true && $case['event'] === null, $case['name']);
            }
        }
    },
];

foreach ($checks as $name => $check) {
    try {
        $check();
    } catch (Throwable $throwable) {
        fwrite(STDERR, \sprintf("floor: %s — %s: %s\n", $name, $throwable::class, $throwable->getMessage()));

        exit(1);
    }

    fwrite(STDOUT, 'ok ' . $name . "\n");
}

fwrite(STDOUT, \sprintf("the package runs on PHP %s\n", PHP_VERSION));
