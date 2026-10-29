<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Data;

use Slen\Sdk\Data\PageDTO;

/**
 * One page of customers, newest first.
 *
 * @extends PageDTO<CustomerDTO>
 */
final readonly class CustomerPageDTO extends PageDTO
{
}
