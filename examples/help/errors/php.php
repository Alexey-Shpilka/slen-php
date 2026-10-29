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

// docs:start catch
use Slen\Sdk\Exceptions\OperationUncertainException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Exceptions\SpendRefusedException;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$input = ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email.']]];

try {
    $execution = $slen->operations()->execute('generate_email', $input, new ExecuteOptions(idempotencyKey: 'order-42:email'));

    echo $execution->status->value, PHP_EOL;
} catch (SpendRefusedException $error) {
    // The spend was refused, and the code says what refused it.
    echo 'refused: ', $error->errorCode, PHP_EOL;
} catch (OperationUncertainException $error) {
    // A provider was called and its answer was lost: the call may have been paid for.
    echo 'uncertain, request ', $error->requestId, PHP_EOL;
} catch (SlenException $error) {
    echo $error->statusCode, ' ', $error->errorCode, ': ', $error->getMessage(), PHP_EOL;
}
// docs:end

// docs:start repeat
$again = $slen->operations()->execute('generate_email', $input, new ExecuteOptions(idempotencyKey: 'order-42:email'));

if ($again->isRejected()) {
    echo 'rejected: ', $again->errorCode, PHP_EOL;
}
// docs:end
