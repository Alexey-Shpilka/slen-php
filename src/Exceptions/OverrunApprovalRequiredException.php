<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `overrun_approval_required`: a run went over its estimate and waits for a person to approve it. */
class OverrunApprovalRequiredException extends SpendRefusedException
{
}
