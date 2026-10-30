<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Resources;

use Slen\Sdk\Data\Health;
use Slen\Sdk\Exceptions\InvalidResponseException;

/** @internal */
final readonly class HealthResource extends BaseResource
{
    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function map(array $data, ?string $requestId = null): Health
    {
        return new Health(
            app: $this->string($data, 'app'),
            release: $this->nullableString($data, 'release'),
            server: $this->string($data, 'server'),
            mysql: $this->string($data, 'mysql'),
            redis: $this->string($data, 'redis'),
            queue: $this->nullableString($data, 'queue'),
            broadcast: $this->nullableString($data, 'broadcast'),
            opcachePreloadedFiles: $this->nullableInt($data, 'opcache_preloaded_files'),
            opcacheCachedScripts: $this->nullableInt($data, 'opcache_cached_scripts'),
            requestId: $requestId,
        );
    }
}
