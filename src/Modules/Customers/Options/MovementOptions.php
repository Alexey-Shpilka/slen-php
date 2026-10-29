<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Options;

/** The options of a credit movement: the account, the note on the statement and the key. */
final readonly class MovementOptions
{
    /**
     * @param string      $creditType     the account moved; not empty
     * @param string|null $idempotencyKey one per movement (`invoice-7781:topup`); generated when null
     * @param float|null  $timeout        seconds each attempt may take, above zero; the config's when null
     * @param int|null    $retries        attempts after the first, zero or more; the config's when null
     */
    public function __construct(
        public string $creditType = 'default',
        public ?string $note = null,
        public ?string $idempotencyKey = null,
        public ?float $timeout = null,
        public ?int $retries = null,
    ) {
    }
}
