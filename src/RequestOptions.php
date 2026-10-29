<?php

declare(strict_types=1);

namespace Slen\Sdk;

/** The options of a call that names nothing more than its subject. */
final readonly class RequestOptions
{
    /**
     * @param float|null $timeout seconds each attempt of this call may take, above zero; the config's when null
     * @param int|null   $retries attempts after the first for this call, zero or more; the config's when null
     */
    public function __construct(
        public ?float $timeout = null,
        public ?int $retries = null,
    ) {
    }
}
