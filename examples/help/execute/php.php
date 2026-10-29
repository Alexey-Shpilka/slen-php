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

// docs:start send
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$execution = $slen->operations()->execute(
    'generate_email',
    ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email to Ada.']]],
    new ExecuteOptions(customer: 'user_8421', context: ['plan' => 'pro'], idempotencyKey: 'order-57:email'),
);
// docs:end

// docs:start result
echo match (true) {
    $execution->isCompleted() => $execution->output['content'],
    $execution->isRejected() => "Refused: {$execution->errorCode} — {$execution->errorMessage}",
    $execution->isPending() => "Not over yet: {$execution->status->value}. Read it again in {$execution->retryAfterSeconds} s.",
    default => "Did not complete: {$execution->status->value}, {$execution->errorCode}",
}, PHP_EOL;
// docs:end
