<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** The flow broke while it ran (a node, a port, a limit of steps, an HTTP call of the flow). Nodes that ran before the failure may have been paid for. */
class FlowRuntimeException extends SlenException
{
}
