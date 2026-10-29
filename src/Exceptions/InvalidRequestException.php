<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** The request was refused as it stands, or the SDK refused it before sending (`invalid_request`); nothing ran. */
class InvalidRequestException extends SlenException
{
}
