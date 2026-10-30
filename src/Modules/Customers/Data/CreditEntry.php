<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Data;

use DateTimeImmutable;
use Slen\Sdk\Modules\Customers\Enums\CreditEntryType;

/** One movement of a customer's credits, with the balance it left behind. */
final readonly class CreditEntry
{
    /**
     * @param string      $credits        signed: negative took credits, positive gave them back
     * @param string|null $reservationId  the hold a commit or a release settles; null otherwise
     */
    public function __construct(
        public string $entryId,
        public string $customerAccountId,
        public string $externalUserId,
        public string $creditType,
        public CreditEntryType $entryType,
        public string $credits,
        public string $balanceAfter,
        public string $availableAfter,
        public ?string $reservationId,
        public ?string $note,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
