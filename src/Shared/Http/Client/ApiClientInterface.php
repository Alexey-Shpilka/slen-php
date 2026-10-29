<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Client;

use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;

/** @internal */
interface ApiClientInterface
{
    /**
     * @throws ConnectionException no answer: the connection did not open, or broke
     * @throws TimeoutException    no answer within the timeout of the client
     */
    public function send(ApiRequestDTO $request): ApiResponseDTO;
}
