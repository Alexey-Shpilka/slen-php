<?php

declare(strict_types=1);

// docs:start client
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;

$slen = SlenSdk::create(new SlenConfig(apiKey: (string) getenv('SLEN_API_KEY')));
// docs:end

// Harness: the SDK above is built for real; from here on it answers from the
// test double instead of the network. Not part of any region.
$slen = $offline($slen);

// docs:start key
use Slen\Sdk\IdempotencyKeys;

// The id of the job of your own queue that sends this request.
$jobId = 'a1f7c2e9';
$key = IdempotencyKeys::forJob($jobId, 'generate_email');
// docs:end

// docs:start send
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$input = ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email.']]];

$execution = $slen->operations()->execute('generate_email', $input, new ExecuteOptions(idempotencyKey: $key));

echo $execution->status->value, PHP_EOL;
// docs:end

// docs:start repeat
// The queue runs the same job again, so the key is the same.
$again = $slen->operations()->execute('generate_email', $input, new ExecuteOptions(idempotencyKey: $key));

if ($again->replayed) {
    echo "Execution {$again->executionId} ran once. This answer is a replay.", PHP_EOL;
}
// docs:end

// docs:start conflict
use Slen\Sdk\Exceptions\IdempotencyConflictException;

try {
    $slen->operations()->execute(
        'generate_email',
        ['messages' => [['role' => 'user', 'content' => 'Write a short thank-you email.']]],
        new ExecuteOptions(idempotencyKey: $key),
    );
} catch (IdempotencyConflictException $exception) {
    echo "Refused: {$exception->errorCode}", PHP_EOL;
}
// docs:end
