<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Data;

use DateTimeImmutable;
use DateTimeZone;

/** @internal */
final readonly class ApiResponseDTO
{
    private const string HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /**
     * @param array<string, string> $headers
     * @param array<mixed>          $body
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public array $body = [],
        public bool $readable = true,
        public int $attempt = 1,
        public bool $afterNoAnswer = false,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<mixed> */
    public function data(): array
    {
        return \is_array($this->body['data'] ?? null) === true ? $this->body['data'] : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function requestId(): ?string
    {
        $error = $this->error();

        return $this->header('X-Request-Id') ?? (\is_string($error['request_id'] ?? null) === true ? $error['request_id'] : null);
    }

    public function errorCode(): ?string
    {
        $code = $this->error()['code'] ?? null;

        return \is_string($code) === true ? $code : null;
    }

    public function retryAfterSeconds(?int $now = null): ?int
    {
        $value = trim($this->header('Retry-After') ?? '');

        if (ctype_digit($value) === true) {
            return (int) $value;
        }

        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, new DateTimeZone('UTC'));

        return $date === false ? null : max(0, $date->getTimestamp() - ($now ?? time()));
    }

    public function attempted(int $attempt, bool $afterNoAnswer): self
    {
        return new self($this->status, $this->headers, $this->body, $this->readable, $attempt, $afterNoAnswer);
    }

    /** @return array<mixed> */
    private function error(): array
    {
        return \is_array($this->body['error'] ?? null) === true ? $this->body['error'] : [];
    }
}
