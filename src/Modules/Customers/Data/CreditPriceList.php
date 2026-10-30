<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Data;

/** The prices of the project: what one run of an operation holds from its customer, by credit type. */
final readonly class CreditPriceList
{
    /** @param list<CreditPrice> $data */
    public function __construct(
        public array $data,
    ) {
    }
}
