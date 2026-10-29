<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Factories;

use InvalidArgumentException;
use Slen\Sdk\Modules\Webhooks\Options\VerifyOptions;
use Slen\Sdk\Modules\Webhooks\Options\WebhooksOptions;
use Slen\Sdk\Modules\Webhooks\Services\EventParser;
use Slen\Sdk\Modules\Webhooks\Services\SignatureVerifier;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Shared\Timing\TimingInterface;

/** @internal */
final readonly class WebhooksModuleFactory
{
    private ?string $secret;

    private int $toleranceSeconds;

    /** @throws InvalidArgumentException */
    public function __construct(
        private Journal $journal,
        WebhooksOptions $webhooksOptions,
        private TimingInterface $timing,
    ) {
        $this->toleranceSeconds = $this->tolerance($webhooksOptions->toleranceSeconds);
        $secret = $webhooksOptions->secret();
        $this->secret = $secret === null || trim($secret) === '' ? null : $secret;
    }

    public function journal(): Journal
    {
        return $this->journal;
    }

    /** @throws InvalidArgumentException when the call passes a blank one, or there is none at all */
    public function secret(#[\SensitiveParameter] ?string $secret): string
    {
        // A blank secret in the call is a bug at the call site: checking with another one would hide it.
        if ($secret !== null && trim($secret) === '') {
            throw new InvalidArgumentException('Slen: the webhook secret passed is empty; pass the signing secret of the endpoint, or none.');
        }

        return $secret ?? $this->secret ?? throw new InvalidArgumentException(
            'Slen: a webhook is checked with the signing secret of its endpoint; pass it, or set webhookSecret in SlenConfig.',
        );
    }

    /** @throws InvalidArgumentException */
    public function signatureVerifier(VerifyOptions $options): SignatureVerifier
    {
        $toleranceSeconds = $this->tolerance($options->toleranceSeconds ?? $this->toleranceSeconds);

        return new SignatureVerifier($toleranceSeconds, $options->now?->getTimestamp() ?? $this->timing->unixSeconds());
    }

    public function eventParser(): EventParser
    {
        return new EventParser();
    }

    /** @throws InvalidArgumentException */
    private function tolerance(int $toleranceSeconds): int
    {
        if ($toleranceSeconds < 1) {
            throw new InvalidArgumentException(\sprintf('Slen: the webhook tolerance must be 1 second or more, got %d.', $toleranceSeconds));
        }

        return $toleranceSeconds;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['secret' => $this->secret === null ? null : '[hidden]', 'toleranceSeconds' => $this->toleranceSeconds];
    }
}
