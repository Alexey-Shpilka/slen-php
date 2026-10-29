<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

use Closure;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;

/** @internal */
final readonly class WaitStateDTO
{
    /** @param (Closure(SlenException, int): void)|null $onPollError */
    public function __construct(
        public string $executionId,
        public ApiRequestDTO $request,
        public float $deadline,
        public ?Closure $onPollError,
    ) {
    }
}
