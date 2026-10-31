<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Options;

use Slen\Sdk\Modules\Customers\Enums\CustomerStatus;

/** The page and the filters of a list of customers. */
final readonly class CustomerListOptions
{
    /**
     * @param int         $limit      1 to 100
     * @param string|null $search     a part of the external user id
     * @param string|null $lowBalance a threshold in credits, not a flag: '10' is "fewer than 10 spendable"
     * @param float|null  $timeout    seconds each attempt may take, above zero; the config's when null
     * @param int|null    $retries    attempts after the first, zero or more; the config's when null
     * @param string|null $requestId  8 to 128 of A-Za-z0-9._:-, the X-Request-Id of every attempt; the server's own when null
     */
    public function __construct(
        public ?string $cursor = null,
        public int $limit = 50,
        public ?string $search = null,
        public ?CustomerStatus $status = null,
        public ?string $creditType = null,
        public ?string $lowBalance = null,
        public ?float $timeout = null,
        public ?int $retries = null,
        public ?string $requestId = null,
    ) {
    }
}
