<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** The organization's plan said no (`plan_limit_reached`, `plan_feature_unavailable`); `details['required_plan']` is the plan to upgrade to. */
class PlanLimitException extends SlenException
{
}
