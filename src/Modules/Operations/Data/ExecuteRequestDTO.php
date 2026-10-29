<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Data;

use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;

/** @internal */
final readonly class ExecuteRequestDTO
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $operation,
        public array $input,
        public ExecuteOptions $options,
        public bool $async,
    ) {
    }
}
