<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks;

use InvalidArgumentException;
use Psr\Log\NullLogger;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\WebhookSignatureException;
use Slen\Sdk\LogMessages;
use Slen\Sdk\Modules\Webhooks\Data\WebhookEventDTO;
use Slen\Sdk\Modules\Webhooks\Factories\WebhooksModuleFactory;
use Slen\Sdk\Modules\Webhooks\Options\VerifyOptions;
use Slen\Sdk\Modules\Webhooks\Options\WebhooksOptions;
use Slen\Sdk\Modules\Webhooks\Services\SignatureVerifier;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Shared\Timing\SystemTiming;

/** Webhooks Slen sends: their Slen-Signature checked against the raw body, and the event they carry. */
final readonly class WebhooksFacade
{
    private const array MESSAGES = [
        SignatureVerifier::MISSING_HEADER => 'the webhook has no Slen-Signature header',
        SignatureVerifier::MALFORMED_HEADER => 'the Slen-Signature header of the webhook cannot be read',
        SignatureVerifier::OUTSIDE_TOLERANCE => 'the time of the webhook signature is further from now than the tolerance',
        SignatureVerifier::NO_MATCHING_SIGNATURE => 'no signature of the webhook matches its raw body and the secret',
    ];

    /** @internal build it with SlenSdk::webhooks() or WebhooksFacade::standalone() */
    public function __construct(private WebhooksModuleFactory $webhooksModuleFactory)
    {
    }

    /**
     * For a receiver with no SDK of its own: it needs the endpoint's secret, not an API key.
     *
     * @throws InvalidArgumentException a tolerance below 1
     */
    public static function standalone(?WebhooksOptions $options = null): self
    {
        $options ??= new WebhooksOptions();

        return new self(new WebhooksModuleFactory(new Journal($options->logger ?? new NullLogger()), $options, new SystemTiming()));
    }

    /**
     * Whether $payload, the raw body, carries a Slen-Signature that holds; a refusal goes to the journal.
     *
     * @throws InvalidArgumentException a blank secret passed, none passed and none configured, a tolerance below 1
     */
    public function verify(string $payload, ?string $signatureHeader, ?VerifyOptions $options = null): bool
    {
        $options ??= new VerifyOptions();
        $signatureCheck = $this->webhooksModuleFactory->signatureVerifier($options)
            ->check($payload, $signatureHeader, $this->webhooksModuleFactory->secret($options->secret()));

        if ($signatureCheck->reason !== null) {
            $this->rejected($signatureCheck->reason, $signatureCheck->ageSeconds);

            return false;
        }

        return true;
    }

    /**
     * Checks the signature and reads the event: a typed DTO for the events the SDK knows, UnknownEventDTO for the rest.
     *
     * @throws WebhookSignatureException the signature does not hold; its $reason says why
     * @throws InvalidResponseException  `webhook_payload_invalid`: signed, but not an event
     * @throws InvalidArgumentException  a blank secret passed, none passed and none configured, a tolerance below 1
     */
    public function constructEvent(string $payload, ?string $signatureHeader, ?VerifyOptions $options = null): WebhookEventDTO
    {
        $options ??= new VerifyOptions();
        $signatureCheck = $this->webhooksModuleFactory->signatureVerifier($options)
            ->check($payload, $signatureHeader, $this->webhooksModuleFactory->secret($options->secret()));

        if ($signatureCheck->reason !== null) {
            $this->rejected($signatureCheck->reason, $signatureCheck->ageSeconds);

            throw new WebhookSignatureException('Slen: ' . self::MESSAGES[$signatureCheck->reason] . '.', $signatureCheck->reason);
        }

        try {
            return $this->webhooksModuleFactory->eventParser()->parse($payload);
        } catch (InvalidResponseException $invalidResponseException) {
            $this->rejected('invalid_payload', $signatureCheck->ageSeconds);

            throw $invalidResponseException;
        }
    }

    private function rejected(string $reason, ?int $ageSeconds): void
    {
        $this->webhooksModuleFactory->journal()->warning(LogMessages::WEBHOOK_REJECTED, ['reason' => $reason, 'age_seconds' => $ageSeconds]);
    }
}
