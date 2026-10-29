<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Options;

use Slen\Sdk\Modules\Customers\Enums\CreditEntryType;

/** The page and the filters of a statement. */
final readonly class EntryListOptions
{
    /**
     * @param int        $limit   1 to 100
     * @param float|null $timeout seconds each attempt may take, above zero; the config's when null
     * @param int|null   $retries attempts after the first, zero or more; the config's when null
     */
    public function __construct(
        public ?string $cursor = null,
        public int $limit = 50,
        public ?string $creditType = null,
        public ?CreditEntryType $entryType = null,
        public ?float $timeout = null,
        public ?int $retries = null,
    ) {
    }
}
