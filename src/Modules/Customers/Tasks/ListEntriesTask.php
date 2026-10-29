<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Tasks;

use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\Data\CreditEntryPageDTO;
use Slen\Sdk\Modules\Customers\Options\EntryListOptions;
use Slen\Sdk\Modules\Customers\Resources\CreditEntryResource;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Tasks\AbstractTask;

/** @internal */
final readonly class ListEntriesTask extends AbstractTask
{
    public function __construct(
        ApiTransport $apiTransport,
        private CreditEntryResource $creditEntryResource,
    ) {
        parent::__construct($apiTransport);
    }

    /** @throws SlenException */
    public function execute(string $externalUserId, EntryListOptions $options): CreditEntryPageDTO
    {
        $apiRequest = new ApiRequestDTO(
            Endpoint::ListEntries,
            ['externalUserId' => $externalUserId],
            [
                'cursor' => $options->cursor,
                'limit' => $options->limit,
                'credit_type' => $options->creditType,
                'entry_type' => $options->entryType?->value,
            ],
            retries: $options->retries,
            timeout: $options->timeout,
        );

        return $this->creditEntryResource->page($this->exchange($apiRequest)->body);
    }
}
