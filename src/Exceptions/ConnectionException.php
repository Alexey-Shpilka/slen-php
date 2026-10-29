<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** Slen could not be reached, or the connection broke before an answer came. No code and no status; whether the server did the work is unknown. */
class ConnectionException extends SlenException
{
}
