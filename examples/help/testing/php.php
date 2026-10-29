<?php

declare(strict_types=1);

// docs:start double
use Slen\Sdk\Testing\FakeHttpClient;

$http = (new FakeHttpClient())->pushJson(200, ['data' => [
    'execution_id' => '74438200973594700',
    'status' => 'completed',
    'operation' => 'generate_email',
    'output' => ['content' => 'Hi Ada, just checking in on the proposal I sent last week.'],
    'error' => null,
    'error_code' => null,
    'error_message' => null,
    'cost' => ['estimated' => '0.00412500', 'actual' => null],
    'cache' => ['hit' => false, 'cached_at' => null],
    'replayed' => false,
    'waiting_on' => null,
    'trace_url' => '/v1/executions/74438200973594700',
]]);
// docs:end

// docs:start client
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;

$slen = SlenSdk::create(new SlenConfig(apiKey: 'key-for-tests', httpClient: $http));
// docs:end

// docs:start call
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$execution = $slen->operations()->execute(
    'generate_email',
    ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email.']]],
    new ExecuteOptions(idempotencyKey: 'order-42:email'),
);
// docs:end

// docs:start inspect
$sent = $http->lastRequest();

echo $execution->status->value, PHP_EOL;
echo $sent->getMethod(), ' ', $sent->getUri(), PHP_EOL;
echo $sent->getHeaderLine('Idempotency-Key'), PHP_EOL;
echo json_decode((string) $sent->getBody(), true)['operation'], PHP_EOL;
// docs:end

// Harness: what the example's own double recorded, for the test that runs this
// file. Not part of any region.
$recorded = $http->requests();
