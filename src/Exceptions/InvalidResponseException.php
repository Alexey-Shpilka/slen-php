<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `invalid_response`: a 2xx answer that cannot be read. The call may have gone through; the status it came with is kept. */
class InvalidResponseException extends SlenException
{
}
