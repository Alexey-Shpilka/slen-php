<?php

declare(strict_types=1);

// docs:start facade
use Slen\Sdk\Laravel\Slen;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

$execution = Slen::operations()->execute(
    'generate_email',
    ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email.']]],
    new ExecuteOptions(idempotencyKey: 'order-42:email'),
);

echo $execution->status->value, PHP_EOL;
// docs:end

// docs:start container
use Slen\Sdk\SlenSdk;

$slen = app(SlenSdk::class);

$customer = $slen->customers()->get('user_8421');

foreach ($customer->accounts as $account) {
    echo $account->creditType, ' ', $account->available, PHP_EOL;
}
// docs:end
