<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Options;

/** The options of a block: its reason. */
final readonly class BlockOptions
{
    /**
     * @param string|null $reason  shown as the block reason; null keeps the one there is
     * @param float|null  $timeout seconds each attempt may take, above zero; the config's when null
     * @param int|null    $retries attempts after the first, zero or more; the config's when null
     */
    public function __construct(
        public ?string $reason = null,
        public ?float $timeout = null,
        public ?int $retries = null,
    ) {
    }
}
