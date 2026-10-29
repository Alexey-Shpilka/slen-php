<?php

declare(strict_types=1);

// docs:start logger
use Psr\Log\AbstractLogger;

// Stands in for the PSR-3 logger of your application: it prints each event with
// the route and, for an answer, its status.
$logger = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $status = isset($context['status']) ? ' ' . $context['status'] : '';

        echo "{$level} {$message} {$context['method']} {$context['route']}{$status}", PHP_EOL;
    }
};
// docs:end

// docs:start client
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;

$slen = SlenSdk::create(new SlenConfig(
    apiKey: (string) getenv('SLEN_API_KEY'),
    logger: $logger,
));
// docs:end

// Harness: the SDK above is built for real; from here on it answers from the
// test double instead of the network. Its logger stays the one above. Not part
// of any region.
$slen = $offline($slen);

// docs:start send
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$execution = $slen->operations()->execute(
    'generate_email',
    ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email.']]],
    new ExecuteOptions(idempotencyKey: 'order-77:email'),
);
// docs:end
