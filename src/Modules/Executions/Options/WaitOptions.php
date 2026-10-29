<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Options;

use Closure;
use Slen\Sdk\Exceptions\SlenException;

/** The options of a wait: how long it may take and who hears of a failed read. */
final readonly class WaitOptions
{
    /**
     * @param int                                      $timeoutSeconds the whole wait, at least 1; each read is bounded by it
     * @param (Closure(SlenException, int): void)|null $onPollError    the failure and how many failed in a row
     */
    public function __construct(
        public int $timeoutSeconds = 300,
        public ?Closure $onPollError = null,
    ) {
    }
}
