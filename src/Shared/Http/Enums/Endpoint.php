<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Enums;

/** @internal */
enum Endpoint: string
{
    case ExecuteOperation = 'POST /v1/operations/execute';
    case GetExecutionStatus = 'GET /v1/executions/{id}/status';
    case GetExecutionTrace = 'GET /v1/executions/{id}';
    case ListCustomers = 'GET /v1/customers';
    case GetCustomer = 'GET /v1/customers/{externalUserId}';
    case CreateCustomer = 'PUT /v1/customers/{externalUserId}';
    case DeleteCustomer = 'DELETE /v1/customers/{externalUserId}';
    case UpdateAccount = 'PUT /v1/customers/{externalUserId}/accounts/{creditType}';
    case BlockCustomer = 'POST /v1/customers/{externalUserId}/block';
    case UnblockCustomer = 'POST /v1/customers/{externalUserId}/unblock';
    case TopUp = 'POST /v1/customers/{externalUserId}/topup';
    case Charge = 'POST /v1/customers/{externalUserId}/charge';
    case Adjust = 'POST /v1/customers/{externalUserId}/adjust';
    case ListEntries = 'GET /v1/customers/{externalUserId}/entries';
    case ListPrices = 'GET /v1/credit-prices';
    case Health = 'GET /health';

    public function method(): HttpMethod
    {
        return HttpMethod::from(explode(' ', $this->value, 2)[0]);
    }

    public function path(): string
    {
        return substr($this->value, \strlen($this->method()->value) + 1);
    }
}
