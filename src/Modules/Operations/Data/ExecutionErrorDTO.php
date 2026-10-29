<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Data;

/** Why a run did not complete: its code, to branch on, and its sentence, to show or log. */
final readonly class ExecutionErrorDTO
{
    public function __construct(
        public string $code,
        public string $message,
    ) {
    }
}
