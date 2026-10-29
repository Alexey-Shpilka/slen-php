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

// docs:start call
use Slen\Sdk\Exceptions\OperationUncertainException;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$input = ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email.']]];
$key = 'order-42:email';

try {
    $execution = $slen->operations()->execute('generate_email', $input, new ExecuteOptions(idempotencyKey: $key));

    echo $execution->status->value, PHP_EOL;
} catch (OperationUncertainException $error) {
    // A provider was called and its answer was lost: the call may have been paid for.
    // Keep the key. Under a new one the same request may pay for the call a second time.
    echo 'outcome unknown: ', $error->errorCode, PHP_EOL;
}
// docs:end

// docs:start repeat
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;

// The same request under the same key runs nothing: it reads the execution the first one opened.
$again = $slen->operations()->execute('generate_email', $input, new ExecuteOptions(idempotencyKey: $key));

if ($again->status === ExecutionStatus::Uncertain) {
    echo $again->status->value, ': ', $again->errorCode, PHP_EOL;
}
// docs:end
