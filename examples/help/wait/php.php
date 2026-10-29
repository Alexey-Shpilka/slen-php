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

// docs:start submit
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$queued = $slen->operations()->executeAsync(
    'summarize_report',
    ['messages' => [['role' => 'user', 'content' => 'Summarize the third-quarter report.']]],
    new ExecuteOptions(idempotencyKey: 'report-2026-q3:summary'),
);

echo $queued->status->value, PHP_EOL;
// docs:end

// docs:start wait
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Executions\Options\WaitOptions;

// In a queue job or a script, not in an HTTP handler: the wait sleeps in the process.
$finished = $slen->executions()->wait($queued->executionId, new WaitOptions(
    timeoutSeconds: 60,
    onPollError: static function (SlenException $error, int $failuresInARow): void {
        echo sprintf('Status read failed (%d in a row): %s', $failuresInARow, $error->getMessage()), PHP_EOL;
    },
));
// docs:end

// docs:start result
echo match (true) {
    $finished->isCompleted() => $finished->output['content'],
    $finished->isPending() => 'Not over yet: ' . $finished->status->value,
    default => sprintf('Ended as %s: %s', $finished->status->value, $finished->errorCode),
}, PHP_EOL;
// docs:end
