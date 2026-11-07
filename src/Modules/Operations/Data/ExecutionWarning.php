<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Data;

/** What a run said about its request when it started and went on with: input_key_ignored, or a code the backend adds. */
final readonly class ExecutionWarning
{
    /** @param list<string>|null $keys with input_key_ignored: the keys of a model call the input carried that no node reads */
    public function __construct(
        public string $code,
        public string $message,
        public ?array $keys = null,
    ) {
    }
}
