<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Options;

use DateTimeInterface;

/** The secret, the tolerance and the moment of one webhook check; the receiver's own where null. */
final readonly class VerifyOptions
{
    /**
     * @param string|null            $secret           the signing secret of the endpoint; not blank
     * @param int|null               $toleranceSeconds how far the signature may be from the moment, 1 or more
     * @param DateTimeInterface|null $now              the moment of the check; now when null
     */
    public function __construct(
        #[\SensitiveParameter]
        private ?string $secret = null,
        public ?int $toleranceSeconds = null,
        public ?DateTimeInterface $now = null,
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
            'secret' => $this->secret === null ? null : '[hidden]',
            'toleranceSeconds' => $this->toleranceSeconds,
            'now' => $this->now,
        ];
    }
}
