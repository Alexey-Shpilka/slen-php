<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Resources;

use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Shared\Resources\HealthResource;

final class HealthResourceTest extends TestCase
{
    public function testMapReadsTheBodyOfHealth(): void
    {
        $health = (new HealthResource())->map([
            'app' => 'slen',
            'release' => 'v1.0.12',
            'server' => 'frankenphp',
            'mysql' => 'ok',
            'redis' => 'down: connection refused',
            'queue' => null,
            'broadcast' => 'ok',
            'opcache_preloaded_files' => 120,
            'opcache_cached_scripts' => null,
        ]);

        self::assertSame('slen', $health->app);
        self::assertSame('v1.0.12', $health->release);
        self::assertSame('frankenphp', $health->server);
        self::assertSame('ok', $health->mysql);
        self::assertSame('down: connection refused', $health->redis);
        self::assertNull($health->queue);
        self::assertSame('ok', $health->broadcast);
        self::assertSame(120, $health->opcachePreloadedFiles);
        self::assertNull($health->opcacheCachedScripts);
    }

    public function testMapRefusesABodyWithoutTheRequiredFields(): void
    {
        $this->expectException(InvalidResponseException::class);

        (new HealthResource())->map(['app' => 'slen']);
    }
}
