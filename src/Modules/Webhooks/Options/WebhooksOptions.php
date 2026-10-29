<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Options;

use Psr\Log\LoggerInterface;
use Slen\Sdk\SlenConfig;

/** The settings of a webhook receiver with no SDK of its own. */
final readonly class WebhooksOptions
{
    /**
     * @param LoggerInterface|null $logger           where a refused webhook is written; nowhere when null
     * @param string|null          $secret           the signing secret of the endpoint; else pass it to each call
     * @param int                  $toleranceSeconds how far the time of a signature may be from now, 1 or more
     */
    public function __construct(
        public ?LoggerInterface $logger = null,
        #[\SensitiveParameter]
        private ?string $secret = null,
        public int $toleranceSeconds = SlenConfig::DEFAULT_WEBHOOK_TOLERANCE_SECONDS,
    ) {
    }

    public function secret(): ?string
    {
        return $this->secret;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'logger' => $this->logger instanceof LoggerInterface ? $this->logger::class : null,
            'secret' => $this->secret === null ? null : '[hidden]',
            'toleranceSeconds' => $this->toleranceSeconds,
        ];
    }
}
