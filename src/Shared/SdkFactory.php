<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared;

use Slen\Sdk\Modules\Customers\CustomersFacade;
use Slen\Sdk\Modules\Customers\Factories\CustomersModuleFactory;
use Slen\Sdk\Modules\Executions\ExecutionsFacade;
use Slen\Sdk\Modules\Executions\Factories\ExecutionsModuleFactory;
use Slen\Sdk\Modules\Operations\Factories\OperationsModuleFactory;
use Slen\Sdk\Modules\Operations\OperationsFacade;
use Slen\Sdk\Modules\Webhooks\Factories\WebhooksModuleFactory;
use Slen\Sdk\Modules\Webhooks\Options\WebhooksOptions;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;
use Slen\Sdk\Shared\Data\SdkModulesDTO;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Client\ApiClient;
use Slen\Sdk\Shared\Http\Client\AuthClientDecorator;
use Slen\Sdk\Shared\Http\Client\HttpContext;
use Slen\Sdk\Shared\Http\Client\HttpJournal;
use Slen\Sdk\Shared\Http\Client\LoggingClientDecorator;
use Slen\Sdk\Shared\Http\Client\RetryClientDecorator;
use Slen\Sdk\Shared\Http\Client\RetryPolicy;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Shared\Observability\Telemetry;
use Slen\Sdk\Shared\Resources\HealthResource;
use Slen\Sdk\Shared\Tasks\GetHealthTask;
use Slen\Sdk\Shared\Timing\TimingInterface;
use Slen\Sdk\SlenConfig;

/** @internal */
final readonly class SdkFactory
{
    public function __construct(
        private HttpContext $httpContext,
        private TimingInterface $timing,
    ) {
    }

    public function modules(SlenConfig $config): SdkModulesDTO
    {
        $telemetry = new Telemetry(new Journal($config->logger), $config->spanRecorder);
        $apiTransport = $this->apiTransport($config, $telemetry->journal);

        return new SdkModulesDTO(
            new OperationsFacade(new OperationsModuleFactory($apiTransport, $telemetry)),
            new ExecutionsFacade(new ExecutionsModuleFactory($apiTransport, $telemetry, $this->timing)),
            new CustomersFacade(new CustomersModuleFactory($apiTransport)),
            new WebhooksFacade(new WebhooksModuleFactory($telemetry->journal, $this->webhooksOptions($config), $this->timing)),
            new GetHealthTask($apiTransport, new HealthResource()),
        );
    }

    public function apiTransport(SlenConfig $config, Journal $journal): ApiTransport
    {
        $httpJournal = new HttpJournal($journal);
        $errorMapper = new ErrorMapper();
        $client = new LoggingClientDecorator(
            new RetryClientDecorator(
                new AuthClientDecorator(new ApiClient($this->httpContext, $config->baseUrl, $httpJournal), $config->apiKey()),
                new RetryPolicy($config->retries, $this->timing),
                $httpJournal,
            ),
            $httpJournal,
            $errorMapper,
        );

        // A read of a wait is bounded only where the transport applies it: any other would warn on every read.
        $readTimeout = $this->httpContext->transport->appliesTimeout() === true ? $config->timeout : null;

        return new ApiTransport($client, $errorMapper, $readTimeout);
    }

    private function webhooksOptions(SlenConfig $config): WebhooksOptions
    {
        return new WebhooksOptions(secret: $config->webhookSecret(), toleranceSeconds: $config->webhookToleranceSeconds);
    }
}
