<?php

declare(strict_types=1);

namespace Slen\Sdk\Data;

/** The answer of GET /health. Each dependency reads "ok", or "down: " and the reason. */
final readonly class Health
{
    public function __construct(
        public string $app,
        public ?string $release,
        public string $server,
        public string $mysql,
        public string $redis,
        public ?string $queue,
        public ?string $broadcast,
        public ?int $opcachePreloadedFiles,
        public ?int $opcacheCachedScripts,
        public ?string $requestId = null,
    ) {
    }
}
