<?php

declare(strict_types=1);

namespace Slen\Sdk;

use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slen\Sdk\Observability\NullSpanRecorder;
use Slen\Sdk\Observability\SpanRecorderInterface;

/** The settings of one SlenSdk, checked when built; the API key and the webhook secret are not public properties. */
final readonly class SlenConfig
{
    public const string DEFAULT_BASE_URL = 'https://api.slen.ai';

    public const float DEFAULT_TIMEOUT = 65.0;

    public const float DEFAULT_CONNECT_TIMEOUT = 5.0;

    public const int DEFAULT_RETRIES = 2;

    public const int DEFAULT_WEBHOOK_TOLERANCE_SECONDS = 300;

    private ?string $webhookSecret;

    /**
     * @param string                       $baseUrl                 another address only for tests and a local stack; the SDK adds /api
     * @param float                        $timeout                 seconds one attempt may take; more than zero
     * @param float                        $connectTimeout          seconds the connection may take to open; more than zero
     * @param int                          $retries                 attempts after the first one, on no answer, 429 and 503; zero or more
     * @param LoggerInterface              $logger                  the journal of the SDK; silent unless given
     * @param SpanRecorderInterface        $spanRecorder            where the spans of runs go; nowhere unless given
     * @param ClientInterface|null         $httpClient              a PSR-18 client, used as it is with its own timeouts; discovered when null
     * @param RequestFactoryInterface|null $requestFactory          a PSR-17 request factory; discovered when null
     * @param StreamFactoryInterface|null  $streamFactory           a PSR-17 stream factory; discovered when null
     * @param string|null                  $webhookSecret           the signing secret of the webhook endpoint; empty is none
     * @param int                          $webhookToleranceSeconds how far the time of a webhook signature may be from now; 1 or more
     *
     * @throws InvalidArgumentException when a value is out of its range
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $apiKey,
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public float $timeout = self::DEFAULT_TIMEOUT,
        public float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        public int $retries = self::DEFAULT_RETRIES,
        public LoggerInterface $logger = new NullLogger(),
        public SpanRecorderInterface $spanRecorder = new NullSpanRecorder(),
        public ?ClientInterface $httpClient = null,
        public ?RequestFactoryInterface $requestFactory = null,
        public ?StreamFactoryInterface $streamFactory = null,
        #[\SensitiveParameter]
        ?string $webhookSecret = null,
        public int $webhookToleranceSeconds = self::DEFAULT_WEBHOOK_TOLERANCE_SECONDS,
    ) {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('Slen: an API key is required; pass a non-empty apiKey.');
        }

        // To an HTTP client a timeout of zero means no timeout at all.
        $this->assertPositive('timeout', $timeout);
        $this->assertPositive('connectTimeout', $connectTimeout);

        if ($retries < 0) {
            throw new InvalidArgumentException(\sprintf('Slen: retries must be zero or more, got %d.', $retries));
        }

        if ($webhookToleranceSeconds < 1) {
            throw new InvalidArgumentException(
                \sprintf('Slen: webhookToleranceSeconds must be 1 or more, got %d.', $webhookToleranceSeconds),
            );
        }

        $this->webhookSecret = $webhookSecret === null || trim($webhookSecret) === '' ? null : $webhookSecret;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function webhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self(
            $this->apiKey,
            $this->baseUrl,
            $this->timeout,
            $this->connectTimeout,
            $this->retries,
            $logger,
            $this->spanRecorder,
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->webhookSecret,
            $this->webhookToleranceSeconds,
        );
    }

    public function withSpanRecorder(SpanRecorderInterface $spanRecorder): self
    {
        return new self(
            $this->apiKey,
            $this->baseUrl,
            $this->timeout,
            $this->connectTimeout,
            $this->retries,
            $this->logger,
            $spanRecorder,
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->webhookSecret,
            $this->webhookToleranceSeconds,
        );
    }

    /** A PSR-18 client of the caller's own, with the factories to build its requests (discovered when null). */
    public function withHttpClient(
        ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): self {
        return new self(
            $this->apiKey,
            $this->baseUrl,
            $this->timeout,
            $this->connectTimeout,
            $this->retries,
            $this->logger,
            $this->spanRecorder,
            $httpClient,
            $requestFactory ?? $this->requestFactory,
            $streamFactory ?? $this->streamFactory,
            $this->webhookSecret,
            $this->webhookToleranceSeconds,
        );
    }

    private function assertPositive(string $name, float $seconds): void
    {
        if (is_finite($seconds) === false || $seconds <= 0.0) {
            throw new InvalidArgumentException(
                \sprintf('Slen: %s must be a positive number of seconds, got %s.', $name, var_export($seconds, true)),
            );
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '[hidden]',
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'retries' => $this->retries,
            'logger' => $this->logger::class,
            'spanRecorder' => $this->spanRecorder::class,
            'httpClient' => $this->httpClient instanceof ClientInterface ? $this->httpClient::class : null,
            'webhookSecret' => $this->webhookSecret === null ? null : '[hidden]',
            'webhookToleranceSeconds' => $this->webhookToleranceSeconds,
        ];
    }
}
