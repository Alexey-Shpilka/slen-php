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

// docs:start create
// The external user id is your own opaque id of the customer — not an email address or a name.
$customer = $slen->customers()->create('user_8421');

echo $customer->externalUserId, ' ', $customer->status->value, PHP_EOL;
// docs:end

// docs:start top-up
use Slen\Sdk\Modules\Customers\Options\MovementOptions;

$account = $slen->customers()->topUp('user_8421', '100.0000', new MovementOptions(note: 'Invoice 7781', idempotencyKey: 'invoice-7781:topup'));

echo $account->balance, PHP_EOL;
// docs:end

// docs:start read
$customer = $slen->customers()->get('user_8421');

foreach ($customer->accounts as $held) {
    echo $held->creditType, ' ', $held->available, PHP_EOL;
}
// docs:end

// docs:start charge
// A service of your own application, paid for in the customer's credits.
$charged = $slen->customers()->charge('user_8421', '5.0000', new MovementOptions(note: 'PDF export', idempotencyKey: 'export-991:charge'));

echo $charged->balance, PHP_EOL;
// docs:end

// docs:start statement
use Slen\Sdk\Modules\Customers\Options\EntryListOptions;

$statement = $slen->customers()->entries('user_8421', new EntryListOptions(limit: 2));

foreach ($statement->data as $entry) {
    echo $entry->entryType->value, ' ', $entry->credits, ' ', $entry->note, PHP_EOL;
}
// docs:end

// docs:start refused
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

try {
    $slen->operations()->execute(
        'generate_email',
        ['messages' => [['role' => 'user', 'content' => 'Write a short follow-up email.']]],
        new ExecuteOptions(customer: 'user_9310', idempotencyKey: 'order-45:email'),
    );
} catch (InsufficientCreditsException $refused) {
    // The customer's account cannot pay for the run: nothing ran, nothing was held.
    echo $refused->errorCode, PHP_EOL;
}
// docs:end

// docs:start block
use Slen\Sdk\Modules\Customers\Options\BlockOptions;

// New runs for the customer are refused from now on; the balance can still be settled.
$blocked = $slen->customers()->block('user_8421', new BlockOptions(reason: 'chargeback'));

echo $blocked->status->value, ' ', $blocked->blockReason, PHP_EOL;
// docs:end
