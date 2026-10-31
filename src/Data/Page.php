<?php

declare(strict_types=1);

namespace Slen\Sdk\Data;

/**
 * One page of a list: its rows and the cursor of the next page, null on the last.
 *
 * @template T
 */
final readonly class Page
{
    /** @param list<T> $data */
    public function __construct(
        public array $data,
        public ?string $nextCursor,
        public ?string $requestId = null,
    ) {
    }
}
