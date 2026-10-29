<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Tasks\AbstractTask;

/**
 * AbstractTask with its protected steps opened to a test.
 */
final readonly class ExposedTask extends AbstractTask
{
    /**
     * @return array<mixed>
     */
    public function sendNow(ApiRequestDTO $request): array
    {
        return $this->send($request);
    }

    public function exchangeNow(ApiRequestDTO $request): ApiResponseDTO
    {
        return $this->exchange($request);
    }

    public function keyFor(?string $key): string
    {
        return $this->idempotencyKey($key);
    }
}
