<?php

declare(strict_types=1);

namespace Slen\Sdk;

use LogicException;
use Psr\Log\LoggerInterface;
use Slen\Sdk\Data\Health;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\CustomersFacade;
use Slen\Sdk\Modules\Executions\ExecutionsFacade;
use Slen\Sdk\Modules\Operations\OperationsFacade;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;
use Slen\Sdk\Shared\Data\SdkModulesDTO;
use Slen\Sdk\Shared\Http\Client\HttpClientFactory;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Shared\SdkFactory;
use Slen\Sdk\Shared\Timing\SystemTiming;

/** The entry point of the SDK: SlenSdk::create(new SlenConfig(apiKey: ...)); building it touches no network. */
final readonly class SlenSdk
{
    private function __construct(
        private SlenConfig $slenConfig,
        private SdkModulesDTO $sdkModulesDTO,
        private SdkFactory $sdkFactory,
    ) {
    }

    /** @throws LogicException when no PSR-18 client or PSR-17 factory can be found */
    public static function create(#[\SensitiveParameter] SlenConfig $config): self
    {
        $sdkFactory = new SdkFactory((new HttpClientFactory())->create($config, new Journal($config->logger)), new SystemTiming());

        return new self($config, $sdkFactory->modules($config), $sdkFactory);
    }

    /** A copy whose journal goes to $logger, over the same HTTP client; this SDK keeps writing where it did. */
    public function withLogger(LoggerInterface $logger): self
    {
        $config = $this->slenConfig->withLogger($logger);

        return new self($config, $this->sdkFactory->modules($config), $this->sdkFactory);
    }

    public function config(): SlenConfig
    {
        return $this->slenConfig;
    }

    /** Runs of flows: execute(), executeAsync(). */
    public function operations(): OperationsFacade
    {
        return $this->sdkModulesDTO->operations;
    }

    /** Runs that exist: get(), wait(), trace(). */
    public function executions(): ExecutionsFacade
    {
        return $this->sdkModulesDTO->executions;
    }

    /** End customers: list(), get(), create(), updateAccount(), block(), unblock(), delete(), topUp(), charge(), adjust(), entries(), prices(). */
    public function customers(): CustomersFacade
    {
        return $this->sdkModulesDTO->customers;
    }

    /** Webhooks: verify(), constructEvent(), with the secret and the tolerance of the config. */
    public function webhooks(): WebhooksFacade
    {
        return $this->sdkModulesDTO->webhooks;
    }

    /**
     * Whether the API serves, and the state of each of its dependencies. Needs no scope.
     *
     * @throws InvalidRequestException a timeout or retries out of range; nothing sent
     * @throws SlenException
     */
    public function health(?RequestOptions $options = null): Health
    {
        $options ??= new RequestOptions();
        $apiRequest = new ApiRequestDTO(Endpoint::Health, envelope: false, retries: $options->retries, timeout: $options->timeout);

        return $this->sdkModulesDTO->health->map($this->sdkModulesDTO->api->data($apiRequest));
    }
}
