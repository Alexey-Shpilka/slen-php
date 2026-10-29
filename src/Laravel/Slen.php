<?php

declare(strict_types=1);

namespace Slen\Sdk\Laravel;

use Illuminate\Support\Facades\Facade;
use Slen\Sdk\SlenSdk;

/**
 * The SlenSdk of the application, built by SlenServiceProvider from config/slen-sdk.php.
 *
 * @method static \Slen\Sdk\Modules\Operations\OperationsFacade operations()
 * @method static \Slen\Sdk\Modules\Executions\ExecutionsFacade executions()
 * @method static \Slen\Sdk\Modules\Customers\CustomersFacade customers()
 * @method static \Slen\Sdk\Modules\Webhooks\WebhooksFacade webhooks()
 * @method static \Slen\Sdk\Data\HealthDTO health(?\Slen\Sdk\RequestOptions $options = null)
 * @method static \Slen\Sdk\SlenSdk withLogger(\Psr\Log\LoggerInterface $logger)
 *
 * @see SlenSdk
 */
final class Slen extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SlenSdk::class;
    }
}
