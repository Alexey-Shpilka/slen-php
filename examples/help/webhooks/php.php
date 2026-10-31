<?php

declare(strict_types=1);

// docs:start request
// Stands in for the request Slen sends to your endpoint: its raw body and its
// Slen-Signature header, signed here with the secret of the example's endpoint.
$secret = 'whsec_example_endpoint';
$payload = '{"id":"74438200973594801","type":"execution.finished","created_at":"2026-10-17T12:00:00+00:00",'
    . '"data":{"execution_id":"74438200973594700","flow_id":"74438200973590001","operation":"generate_email","status":"completed","error_code":null}}';
$timestamp = time();
$signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
// docs:end

// docs:start receive
use Slen\Sdk\Modules\Webhooks\Data\CustomerBalanceLowEvent;
use Slen\Sdk\Modules\Webhooks\Data\ExecutionFinishedEvent;
use Slen\Sdk\Modules\Webhooks\Options\WebhooksOptions;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;

// A receiver needs the secret of the endpoint, not an API key. Pass the body as it arrived.
$webhooks = WebhooksFacade::standalone(new WebhooksOptions(secret: $secret));

$event = $webhooks->constructEvent($payload, $signature);

if ($event instanceof ExecutionFinishedEvent) {
    echo $event->type, ': ', $event->executionId, ' ', $event->status->value, PHP_EOL;
}

if ($event instanceof CustomerBalanceLowEvent) {
    echo $event->type, ': ', $event->externalUserId, PHP_EOL;
}
// docs:end

// docs:start refused
use Slen\Sdk\Exceptions\WebhookSignatureException;

try {
    $webhooks->constructEvent(str_replace('completed', 'failed', $payload), $signature);
} catch (WebhookSignatureException $refused) {
    // Not the body Slen signed: answer 400 and do nothing with it.
    echo 'refused: ', $refused->reason, PHP_EOL;
}
// docs:end
