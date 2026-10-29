<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Options;

/** The filters of the price list. */
final readonly class PriceListOptions
{
    /**
     * @param float|null $timeout seconds each attempt may take, above zero; the config's when null
     * @param int|null   $retries attempts after the first, zero or more; the config's when null
     */
    public function __construct(
        public ?string $operationCode = null,
        public ?string $creditType = null,
        public ?float $timeout = null,
        public ?int $retries = null,
    ) {
    }
}
