<?php

declare(strict_types=1);

namespace Slen\Sdk;

use InvalidArgumentException;

/** Idempotency keys: one per unit of work, so a request repeated under its key is answered, not done twice. */
final class IdempotencyKeys
{
    private function __construct()
    {
    }

    /** A random key (UUID v4), for a call with no unit of work of its own to name it after. */
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * The key of a queue job's call: the same for every attempt of the job, so a retried job gets the first run back.
     *
     * @throws InvalidArgumentException when the job id or the operation is empty
     */
    public static function forJob(string $jobId, string $operation): string
    {
        if (trim($jobId) === '' || trim($operation) === '') {
            throw new InvalidArgumentException('Slen: the key of a job needs the id of the job and the operation.');
        }

        return \sprintf('job:%s:%s', $jobId, $operation);
    }
}
