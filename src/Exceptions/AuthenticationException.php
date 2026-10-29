<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** The key is missing, wrong, not allowed this call, or its organization is suspended. Nothing ran; fix the configuration rather than repeat the call. */
class AuthenticationException extends SlenException
{
}
