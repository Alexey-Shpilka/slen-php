<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Client;

use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Throwable;

/** @internal */
final readonly class ApiClient implements ApiClientInterface
{
    private const int CURL_TIMED_OUT = 28;

    private string $apiBase;

    public function __construct(
        private HttpContext $httpContext,
        string $baseUrl,
        private HttpJournal $httpJournal,
    ) {
        $this->apiBase = rtrim($baseUrl, '/') . '/api';
    }

    public function send(ApiRequestDTO $request): ApiResponseDTO
    {
        $transport = $this->httpContext->transport;

        if ($request->timeout !== null && $request->attempt === 1 && $transport->appliesTimeout() === false) {
            $this->httpJournal->timeoutNotApplied($request, $transport->clientClass());
        }

        try {
            $response = $transport->send($this->build($request), $request->timeout);
            // A body that stalls after its headers is no answer either.
            $raw = $response->getBody()->getContents();
        } catch (ClientExceptionInterface | RuntimeException $throwable) {
            throw $this->noAnswer($request, $throwable);
        }

        return $this->read($request, $response, $raw);
    }

    private function build(ApiRequestDTO $request): RequestInterface
    {
        $message = $this->httpContext->requestFactory
            ->createRequest($request->endpoint->method()->value, $this->url($request))
            ->withHeader('Accept', 'application/json');

        foreach ($request->headers as $name => $value) {
            $message = $message->withHeader($name, $value);
        }

        if ($request->idempotencyKey !== null) {
            $message = $message->withHeader('Idempotency-Key', $request->idempotencyKey);
        }

        if ($request->requestId !== null) {
            $message = $message->withHeader('X-Request-Id', $request->requestId);
        }

        if ($request->json === null) {
            return $message;
        }

        return $message
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->httpContext->streamFactory->createStream($request->json));
    }

    private function url(ApiRequestDTO $request): string
    {
        $path = (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $match) use ($request): string {
                return rawurlencode($request->pathParams[$match[1]] ?? '');
            },
            $request->endpoint->path(),
        );
        $query = [];

        foreach ($request->query as $name => $value) {
            if ($value !== null) {
                $query[$name] = \is_bool($value) === true ? var_export($value, true) : (string) $value;
            }
        }

        return $this->apiBase . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private function read(ApiRequestDTO $request, ResponseInterface $response, string $raw): ApiResponseDTO
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }

        $status = $response->getStatusCode();
        $body = $this->decode($raw);

        return new ApiResponseDTO($status, $headers, $body ?? [], $this->readable($request, $status, $body), $request->attempt);
    }

    /** @return array<mixed>|null null when the body is empty or not a JSON object or list */
    private function decode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);

        return \is_array($decoded) === true ? $decoded : null;
    }

    /** @param array<mixed>|null $body */
    private function readable(ApiRequestDTO $request, int $status, ?array $body): bool
    {
        if ($status < 200 || $status >= 300 || $status === 204) {
            return true;
        }

        if ($body === null) {
            return false;
        }

        return $request->envelope === false || \is_array($body['data'] ?? null) === true;
    }

    private function noAnswer(ApiRequestDTO $request, Throwable $throwable): SlenException
    {
        if ($this->timedOut($throwable) === true) {
            return new TimeoutException(
                \sprintf('Slen did not answer %s in time.', $request->describe()),
                requestId: $request->requestId,
                previous: $throwable,
            );
        }

        return new ConnectionException(
            \sprintf('Slen could not be reached for %s.', $request->describe()),
            requestId: $request->requestId,
            previous: $throwable,
        );
    }

    private function timedOut(Throwable $throwable): bool
    {
        for ($cause = $throwable; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($this->isTimeout($cause) === true) {
                return true;
            }
        }

        return false;
    }

    private function isTimeout(Throwable $cause): bool
    {
        if ($cause instanceof TimeoutExceptionInterface || $cause instanceof ConnectTimeoutException) {
            return true;
        }

        if ($cause instanceof NetworkTimeoutException || $cause instanceof ResponseTimeoutException) {
            return true;
        }

        // Guzzle 7 tells a cURL timeout by its errno alone; Guzzle 8 has no such method.
        $context = method_exists($cause, 'getHandlerContext') === true ? $cause->getHandlerContext() : [];

        if (\is_array($context) === true && ($context['errno'] ?? null) === self::CURL_TIMED_OUT) {
            return true;
        }

        return stripos($cause->getMessage(), 'timed out') !== false;
    }
}
