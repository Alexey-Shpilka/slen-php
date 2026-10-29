<?php

declare(strict_types=1);

namespace Slen\Sdk\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Slen\Sdk\Observability\NullSpanRecorder;
use Slen\Sdk\Observability\Psr3SpanRecorder;
use Slen\Sdk\Observability\SpanRecorderInterface;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;

/** Builds SlenConfig, the span recorder and SlenSdk from config/slen-sdk.php, as singletons. */
final class SlenServiceProvider extends ServiceProvider
{
    private const string CONFIG = __DIR__ . '/../../config/slen-sdk.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'slen-sdk');

        $this->app->singleton(SpanRecorderInterface::class, static function (Container $container): SpanRecorderInterface {
            $containerConfigReader = new ContainerConfigReader($container);

            if ($containerConfigReader->string('spans') !== 'log') {
                return new NullSpanRecorder();
            }

            return new Psr3SpanRecorder($containerConfigReader->logger(), $containerConfigReader->string('span_level') ?? 'info');
        });

        $this->app->singleton(SlenConfig::class, static function (Container $container): SlenConfig {
            $containerConfigReader = new ContainerConfigReader($container);

            /** @var SpanRecorderInterface $spanRecorder */
            $spanRecorder = $container->make(SpanRecorderInterface::class);

            return new SlenConfig(
                apiKey: $containerConfigReader->string('api_key') ?? '',
                // Not a key of the published file: only a test or a local stack sets it.
                baseUrl: $containerConfigReader->string('base_url') ?? SlenConfig::DEFAULT_BASE_URL,
                timeout: $containerConfigReader->float('timeout'),
                connectTimeout: $containerConfigReader->float('connect_timeout'),
                retries: $containerConfigReader->int('retries'),
                logger: $containerConfigReader->logger(),
                spanRecorder: $spanRecorder,
                webhookSecret: $containerConfigReader->string('webhook_secret'),
                webhookToleranceSeconds: $containerConfigReader->int('webhook_tolerance'),
            );
        });

        $this->app->singleton(SlenSdk::class, static function (Container $container): SlenSdk {
            /** @var SlenConfig $slenConfig */
            $slenConfig = $container->make(SlenConfig::class);

            return SlenSdk::create($slenConfig);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole() === true) {
            $this->publishes([self::CONFIG => $this->app->configPath('slen-sdk.php')], 'slen-sdk-config');
        }
    }
}
