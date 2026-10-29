<?php

declare(strict_types=1);

namespace Slen\Sdk;

/** The events of the journal, the message of a PSR-3 record; the TypeScript SDK writes the same names and fields. */
final class LogMessages
{
    public const string REQUEST = 'slen.request';

    public const string RESPONSE = 'slen.response';

    public const string RETRY = 'slen.retry';

    public const string REFUSED = 'slen.refused';

    public const string UNAUTHORIZED = 'slen.unauthorized';

    public const string FAILED = 'slen.failed';

    public const string WAIT_POLL = 'slen.wait.poll';

    public const string WAIT_POLL_FAILED = 'slen.wait.poll_failed';

    public const string WEBHOOK_REJECTED = 'slen.webhook.rejected';

    public const string CONFIG_TIMEOUT_NOT_APPLIED = 'slen.config.timeout_not_applied';

    private function __construct()
    {
    }
}
