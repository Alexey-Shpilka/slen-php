<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Data;

use Slen\Sdk\Data\PageDTO;

/**
 * One page of a customer's statement, newest first.
 *
 * @extends PageDTO<CreditEntryDTO>
 */
final readonly class CreditEntryPageDTO extends PageDTO
{
}
