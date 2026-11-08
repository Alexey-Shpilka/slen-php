<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Logging;

use Slen\Sdk\LogMessages;
use Slen\Sdk\Modules\Operations\Data\ExecutionWarning;

/** @internal */
final readonly class ExecutionWarningsJournal
{
    public function __construct(private Journal $journal)
    {
    }

    /** @param list<ExecutionWarning> $warnings */
    public function write(string $executionId, array $warnings): void
    {
        if ($warnings === []) {
            return;
        }

        // The codes and the keys, never the message: the journal carries no text of the server.
        $this->journal->warning(LogMessages::EXECUTION_WARNINGS, [
            'execution_id' => $executionId,
            'warnings' => array_map(static function (ExecutionWarning $executionWarning): array {
                return ['code' => $executionWarning->code, 'keys' => $executionWarning->keys];
            }, $warnings),
        ]);
    }
}
