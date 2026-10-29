<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `overrun_stopped`: a run went over its estimate and the policy stopped it. */
class OverrunStoppedException extends SpendRefusedException
{
}
