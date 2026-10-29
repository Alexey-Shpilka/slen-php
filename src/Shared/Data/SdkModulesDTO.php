<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Data;

use Slen\Sdk\Modules\Customers\CustomersFacade;
use Slen\Sdk\Modules\Executions\ExecutionsFacade;
use Slen\Sdk\Modules\Operations\OperationsFacade;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;
use Slen\Sdk\Shared\Tasks\GetHealthTask;

/** @internal */
final readonly class SdkModulesDTO
{
    public function __construct(
        public OperationsFacade $operations,
        public ExecutionsFacade $executions,
        public CustomersFacade $customers,
        public WebhooksFacade $webhooks,
        public GetHealthTask $health,
    ) {
    }
}
