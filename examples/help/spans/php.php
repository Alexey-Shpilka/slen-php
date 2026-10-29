<?php

declare(strict_types=1);

// docs:start recorder
use Psr\Log\AbstractLogger;
use Slen\Sdk\Observability\Psr3SpanRecorder;

// Stands in for the PSR-3 logger of your application: it prints the fields this page talks about.
$logger = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        echo "{$message}: {$context['operation']} {$context['outcome']} {$context['status']} {$context['operation_code']} {$context['execution_id']} {$context['estimated']}", PHP_EOL;
    }
};

$recorder = new Psr3SpanRecorder($logger);
// docs:end

// docs:start client
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;

$slen = SlenSdk::create(new SlenConfig(
    apiKey: (string) getenv('SLEN_API_KEY'),
    spanRecorder: $recorder,
));
// docs:end

// Harness: the SDK above is built for real; from here on it answers from the
// test double instead of the network. Its span recorder stays the one above.
// Not part of any region.
$slen = $offline($slen);

// docs:start send
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$execution = $slen->operations()->execute(
    'generate_email',
    ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email to Ada.']]],
    new ExecuteOptions(idempotencyKey: 'order-61:email'),
);
// docs:end
