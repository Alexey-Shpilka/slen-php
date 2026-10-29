<?php

declare(strict_types=1);

// docs:start client
use Psr\Log\AbstractLogger;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;

// Stands in for the PSR-3 logger of your application: this one prints what went wrong.
$logger = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === 'warning' || $level === 'error') {
            echo $level, ' ', $message, PHP_EOL;
        }
    }
};

// SlenConfig reads no environment variable: the key is passed.
$slen = SlenSdk::create(new SlenConfig(
    apiKey: (string) getenv('SLEN_API_KEY'),
    timeout: 120.0,
    connectTimeout: 3.0,
    retries: 3,
    logger: $logger,
));
// docs:end

// Harness: the SDK above is built for real; from here on it answers from the
// test double instead of the network. Not part of any region.
$slen = $offline($slen);

// docs:start call
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$execution = $slen->operations()->execute(
    'generate_email',
    ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email.']]],
    new ExecuteOptions(idempotencyKey: 'order-42:email'),
);

echo $execution->status->value, PHP_EOL;
// docs:end
