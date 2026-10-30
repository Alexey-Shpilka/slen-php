<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http;

use Closure;
use Generator;
use Slen\Sdk\Data\Page;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\SlenException;

/** @internal */
final readonly class CursorPager
{
    /**
     * @template T
     *
     * @param Closure(?string): Page<T> $fetch
     *
     * @return Generator<int, T, mixed, void>
     *
     * @throws SlenException
     */
    public function pages(Closure $fetch, ?string $from): Generator
    {
        $cursor = $from;

        do {
            $page = $fetch($cursor);

            // Keys run on across pages: `yield from` would repeat them, and iterator_to_array() would drop rows.
            foreach ($page->data as $row) {
                yield $row;
            }

            if ($page->nextCursor !== null && $page->nextCursor === $cursor) {
                throw new InvalidResponseException(
                    'Slen answered a page with the cursor it was asked for; reading on would read the same page forever.',
                    'invalid_response',
                );
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }
}
