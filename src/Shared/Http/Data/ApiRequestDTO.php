<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Data;

use JsonException;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Shared\Http\Enums\Endpoint;

/** @internal */
final readonly class ApiRequestDTO
{
    private const int JSON_OUT = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public ?string $json;

    /**
     * @param array<string, string>      $pathParams
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body
     * @param array<string, string>      $headers
     *
     * @throws InvalidRequestException `invalid_request`: a path value or an idempotency key that is missing or empty,
     *                                 a timeout or retries out of range, a body that is not JSON; nothing is sent
     */
    public function __construct(
        public Endpoint $endpoint,
        public array $pathParams = [],
        public array $query = [],
        public ?array $body = null,
        public ?string $idempotencyKey = null,
        public bool $envelope = true,
        public ?int $retries = null,
        public ?float $timeout = null,
        public array $headers = [],
        public int $attempt = 1,
    ) {
        foreach ($this->placeholders() as $name) {
            if (trim($this->pathParams[$name] ?? '') === '') {
                throw $this->refused(\sprintf('the %s of %s is empty', $name, $this->endpoint->value));
            }
        }

        // A blank key is refused rather than replaced: a new one would turn the caller's retry into a second run.
        if ($this->idempotencyKey !== null && trim($this->idempotencyKey) === '') {
            throw $this->refused(\sprintf('the idempotency key of %s is empty; pass one that is not, or none', $this->endpoint->value));
        }

        if ($this->timeout !== null && (is_finite($this->timeout) === false || $this->timeout <= 0.0)) {
            throw $this->refused(
                \sprintf('the timeout of %s must be a positive number of seconds, got %s', $this->endpoint->value, var_export($this->timeout, true)),
            );
        }

        if ($this->retries !== null && $this->retries < 0) {
            throw $this->refused(\sprintf('the retries of %s must be zero or more, got %d', $this->endpoint->value, $this->retries));
        }

        $this->json = $this->encode();
    }

    public function withHeader(string $name, string $value): self
    {
        return new self(
            $this->endpoint,
            $this->pathParams,
            $this->query,
            $this->body,
            $this->idempotencyKey,
            $this->envelope,
            $this->retries,
            $this->timeout,
            [...$this->headers, $name => $value],
            $this->attempt,
        );
    }

    public function withAttempt(int $attempt): self
    {
        return new self(
            $this->endpoint,
            $this->pathParams,
            $this->query,
            $this->body,
            $this->idempotencyKey,
            $this->envelope,
            $this->retries,
            $this->timeout,
            $this->headers,
            $attempt,
        );
    }

    /** @throws InvalidRequestException */
    public function withTimeout(float $timeout): self
    {
        return new self(
            $this->endpoint,
            $this->pathParams,
            $this->query,
            $this->body,
            $this->idempotencyKey,
            $this->envelope,
            $this->retries,
            $timeout,
            $this->headers,
            $this->attempt,
        );
    }

    public function describe(): string
    {
        return $this->endpoint->value;
    }

    /** @return list<string> */
    private function placeholders(): array
    {
        preg_match_all('/\{(\w+)\}/', $this->endpoint->path(), $matches);

        return $matches[1];
    }

    private function encode(): ?string
    {
        if ($this->body === null) {
            return null;
        }

        // An empty body is an object on the wire, not a list.
        if ($this->body === []) {
            return '{}';
        }

        try {
            return json_encode($this->body, self::JSON_OUT);
        } catch (JsonException $jsonException) {
            throw $this->refused(\sprintf('the body of %s cannot be encoded as JSON', $this->endpoint->value), $jsonException);
        }
    }

    private function refused(string $what, ?JsonException $jsonException = null): InvalidRequestException
    {
        return new InvalidRequestException('Slen: ' . $what . '.', 'invalid_request', previous: $jsonException);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint->value, 'attempt' => $this->attempt];
    }
}
