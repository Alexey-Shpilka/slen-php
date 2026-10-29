<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Data;

use DateTimeImmutable;

/** How many credits of $creditType one run of $operationCode holds from the customer it is for. */
final readonly class CreditPriceDTO
{
    public function __construct(
        public string $creditPriceId,
        public string $projectId,
        public string $operationCode,
        public string $creditType,
        public string $credits,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
