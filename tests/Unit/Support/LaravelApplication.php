<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Slen\Sdk\Laravel\SlenServiceProvider;

/**
 * A container as far as the provider sees an application: a `slen-sdk` configuration and nothing else.
 * The configuration counts as cached, so the provider does not load config/slen-sdk.php (its env() needs a framework).
 */
final class LaravelApplication extends Container implements CachesConfiguration
{
    /**
     * @param array<string, mixed> $settings the keys of slen-sdk
     */
    public static function withTheProvider(array $settings): self
    {
        $application = new self();
        $application->instance('config', new class ($settings) implements Repository {
            /**
             * @param array<string, mixed> $settings
             */
            public function __construct(private array $settings)
            {
            }

            public function has($key): bool
            {
                return \array_key_exists(substr($key, \strlen('slen-sdk.')), $this->settings);
            }

            /**
             * @param array<mixed>|string $key
             */
            public function get($key, $default = null): mixed
            {
                return \is_string($key) ? $this->settings[substr($key, \strlen('slen-sdk.'))] ?? $default : $default;
            }

            /**
             * @return array<string, mixed>
             */
            public function all(): array
            {
                return $this->settings;
            }

            /**
             * @param array<mixed>|string $key
             */
            public function set($key, $value = null): void
            {
            }

            public function prepend($key, $value): void
            {
            }

            public function push($key, $value): void
            {
            }
        });

        // The provider asks the application only what a container answers.
        (new SlenServiceProvider($application))->register(); // @phpstan-ignore argument.type

        return $application;
    }

    public function configurationIsCached(): bool
    {
        return true;
    }

    public function getCachedConfigPath(): string
    {
        return '';
    }

    public function getCachedServicesPath(): string
    {
        return '';
    }
}
