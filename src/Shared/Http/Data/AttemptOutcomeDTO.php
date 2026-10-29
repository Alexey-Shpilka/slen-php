<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Data;

use Slen\Sdk\Exceptions\SlenException;

/** @internal */
final readonly class AttemptOutcomeDTO
{
    public function __construct(
        public ?ApiResponseDTO $response,
        public ?SlenException $noAnswer = null,
    ) {
    }
}
