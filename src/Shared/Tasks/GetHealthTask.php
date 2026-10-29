<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Tasks;

use Slen\Sdk\Data\HealthDTO;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Resources\HealthResource;

/** @internal */
final readonly class GetHealthTask extends AbstractTask
{
    public function __construct(
        ApiTransport $apiTransport,
        private HealthResource $healthResource,
    ) {
        parent::__construct($apiTransport);
    }

    /** @throws SlenException */
    public function execute(RequestOptions $options): HealthDTO
    {
        $apiRequest = new ApiRequestDTO(Endpoint::Health, envelope: false, retries: $options->retries, timeout: $options->timeout);

        return $this->healthResource->map($this->send($apiRequest));
    }
}
