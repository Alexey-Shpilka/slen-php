<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Client;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\NullLogger;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\Shared\Http\Client\ApiClient;
use Slen\Sdk\Shared\Http\Client\HttpContext;
use Slen\Sdk\Shared\Http\Client\HttpJournal;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Http\Transport\Psr18Transport;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Tests\Unit\Support\HandlerContextFailure;
use Slen\Sdk\Tests\Unit\Support\NetworkFailure;
use Slen\Sdk\Tests\Unit\Support\QueuedHttpClient;
use Symfony\Component\HttpClient\Exception\TimeoutException as SymfonyTimeoutException;

final class ApiClientTest extends TestCase
{
    public function testSendBuildsTheAddressFromTheRouteItsValuesAndTheQuery(): void
    {
        $http = (new QueuedHttpClient())->pushJson(200, ['data' => []]);

        $this->client($http, 'https://api.test/')->send(new ApiRequestDTO(
            Endpoint::ListEntries,
            pathParams: ['externalUserId' => 'jane+doe@example.com'],
            query: ['limit' => 5, 'cursor' => null, 'low' => true, 'credit_type' => 'default'],
        ));

        self::assertSame(
            'https://api.test/api/v1/customers/jane%2Bdoe%40example.com/entries?limit=5&low=true&credit_type=default',
            (string) $http->lastRequest()->getUri(),
        );
    }

    public function testSendCarriesTheHeadersOfTheRequestAndJsonOnlyWithABody(): void
    {
        $http = (new QueuedHttpClient())->pushJson(200, ['data' => []])->pushJson(200, ['data' => []]);
        $client = $this->client($http);

        $client->send(new ApiRequestDTO(Endpoint::ListCustomers, headers: ['User-Agent' => 'test']));

        $read = $http->lastRequest();
        $client->send(new ApiRequestDTO(Endpoint::ExecuteOperation, body: ['operation' => 'a'], idempotencyKey: 'key-1'));
        $write = $http->lastRequest();

        self::assertSame('application/json', $read->getHeaderLine('Accept'));
        self::assertSame('test', $read->getHeaderLine('User-Agent'));
        self::assertFalse($read->hasHeader('Content-Type'));
        self::assertFalse($read->hasHeader('Idempotency-Key'));
        self::assertSame('', (string) $read->getBody());
        self::assertSame('POST', $write->getMethod());
        self::assertSame('application/json', $write->getHeaderLine('Content-Type'));
        self::assertSame('key-1', $write->getHeaderLine('Idempotency-Key'));
        self::assertSame('{"operation":"a"}', (string) $write->getBody());
    }

    public function testSendEncodesTheBodyAsTheWireExpectsIt(): void
    {
        $http = (new QueuedHttpClient())->pushJson(200, ['data' => []])->pushJson(200, ['data' => []]);
        $client = $this->client($http);

        $client->send(new ApiRequestDTO(Endpoint::ExecuteOperation, body: ['note' => 'Ünïcode/ok', 'amount' => 1.0]));

        $encoded = (string) $http->lastRequest()->getBody();
        $client->send(new ApiRequestDTO(Endpoint::ExecuteOperation, body: []));

        self::assertSame('{"note":"Ünïcode/ok","amount":1.0}', $encoded);
        self::assertSame('{}', (string) $http->lastRequest()->getBody());
    }

    public function testSendReadsTheAnswerWithLowerCaseHeadersAndTheAttempt(): void
    {
        $http = (new QueuedHttpClient())->pushJson(402, ['error' => ['code' => 'insufficient_credits']], ['X-Request-Id' => 'req-1']);

        $response = $this->client($http)->send((new ApiRequestDTO(Endpoint::ListPrices))->withAttempt(3));

        self::assertSame(402, $response->status);
        self::assertSame('req-1', $response->headers['x-request-id']);
        self::assertSame(['error' => ['code' => 'insufficient_credits']], $response->body);
        self::assertSame(3, $response->attempt);
        self::assertFalse($response->afterNoAnswer);
    }

    public function testSendKeepsARefusalWhoseBodyIsNotJsonAsAnEmptyBody(): void
    {
        $http = (new QueuedHttpClient())->pushRaw(502, '<html>Bad gateway</html>', ['Content-Type' => 'text/html']);

        $response = $this->client($http)->send(new ApiRequestDTO(Endpoint::ListPrices));

        self::assertSame(502, $response->status);
        self::assertSame([], $response->body);
    }

    public function testSendFlagsASuccessItCannotRead(): void
    {
        $cases = [
            'not json' => (new QueuedHttpClient())->pushRaw(200, '<html>ok</html>'),
            'no body' => (new QueuedHttpClient())->pushRaw(200, ''),
            'no data' => (new QueuedHttpClient())->pushJson(200, ['status' => 'ok']),
            'data that is not an object' => (new QueuedHttpClient())->pushJson(201, ['data' => 'ok']),
        ];

        foreach ($cases as $case => $http) {
            $response = $this->client($http)->send(new ApiRequestDTO(Endpoint::ListPrices));

            self::assertFalse($response->readable, $case);
            self::assertTrue($response->isSuccessful(), $case);
        }

        $refusal = $this->client((new QueuedHttpClient())->pushRaw(502, '<html>Bad gateway</html>'))->send(new ApiRequestDTO(Endpoint::ListPrices));

        self::assertTrue($refusal->readable);
    }

    public function testSendTakesABodyThatBreaksOffForNoAnswer(): void
    {
        $psr17Factory = new Psr17Factory();
        $detached = $psr17Factory->createStream('{"data":');
        $detached->detach();

        $http = (new QueuedHttpClient())->pushResponse($psr17Factory->createResponse(200)->withBody($detached));

        $this->expectException(ConnectionException::class);

        $this->client($http)->send(new ApiRequestDTO(Endpoint::ListPrices));
    }

    public function testSendAcceptsAnAnswerWithoutEnvelopeWhereNoneIsExpected(): void
    {
        $http = (new QueuedHttpClient())->pushJson(200, ['app' => 'slen'])->pushRaw(204, '');
        $client = $this->client($http);

        $health = $client->send(new ApiRequestDTO(Endpoint::Health, envelope: false));
        $deleted = $client->send(new ApiRequestDTO(Endpoint::DeleteCustomer, ['externalUserId' => 'u1']));

        self::assertSame(['app' => 'slen'], $health->body);
        self::assertTrue($health->readable);
        self::assertSame(204, $deleted->status);
        self::assertTrue($deleted->readable);
        self::assertSame([], $deleted->body);
    }

    public function testSendTurnsANetworkFailureIntoAConnectionException(): void
    {
        $failure = new NetworkFailure('Could not resolve host');
        $http = (new QueuedHttpClient())->pushException($failure);

        try {
            $this->client($http)->send(new ApiRequestDTO(Endpoint::GetCustomer, ['externalUserId' => 'jane@example.com']));
            self::fail('Expected ConnectionException');
        } catch (ConnectionException $connectionException) {
            self::assertSame($failure, $connectionException->getPrevious());
            self::assertNull($connectionException->errorCode);
            self::assertNull($connectionException->statusCode);
            self::assertStringNotContainsString('jane', $connectionException->getMessage());
        }
    }

    public function testSendTellsATimeoutFromOtherNetworkFailures(): void
    {
        $this->assertTimeouts([
            'guzzle 7, cURL errno 28' => new HandlerContextFailure('cURL error 28', 28),
            'symfony, under a network exception' => new NetworkFailure('Idle timeout reached', new SymfonyTimeoutException('Idle timeout reached')),
            'any client, by its message' => new NetworkFailure('Operation timed out after 5001 milliseconds'),
        ]);

        $refusals = [
            'guzzle, refused' => new ConnectException('cURL error 7: refused', new Request('GET', 'https://api.slen.ai/api/health')),
            'guzzle 7, cURL errno 7' => new HandlerContextFailure('cURL error 7: refused', 7),
        ];

        foreach ($refusals as $case => $failure) {
            $http = (new QueuedHttpClient())->pushException($failure);

            try {
                $this->client($http)->send(new ApiRequestDTO(Endpoint::Health, envelope: false));
                self::fail('Expected ConnectionException: ' . $case);
            } catch (ConnectionException $connectionException) {
                self::assertSame($failure, $connectionException->getPrevious(), $case);
            }
        }
    }

    public function testSendTellsAGuzzle8TimeoutByItsType(): void
    {
        if (class_exists(ConnectTimeoutException::class) === false) {
            self::markTestSkipped('Guzzle 7 is installed: its timeouts carry a cURL errno, held by the test above.');
        }

        $request = new Request('GET', 'https://api.slen.ai/api/health');

        $this->assertTimeouts([
            'a connect timeout' => new ConnectTimeoutException('cURL error 28', $request),
            'a timeout before the headers' => new NetworkTimeoutException('cURL error 28', $request),
            'a timeout after the headers' => new ResponseTimeoutException('cURL error 28', $request, new Response(200)),
        ]);
    }

    /** @param array<string, ClientExceptionInterface> $timeouts */
    private function assertTimeouts(array $timeouts): void
    {
        foreach ($timeouts as $case => $failure) {
            $http = (new QueuedHttpClient())->pushException($failure);

            try {
                $this->client($http)->send(new ApiRequestDTO(Endpoint::Health, envelope: false));
                self::fail('Expected TimeoutException: ' . $case);
            } catch (TimeoutException $timeoutException) {
                self::assertSame($failure, $timeoutException->getPrevious(), $case);
            }
        }
    }

    private function client(QueuedHttpClient $http, string $baseUrl = 'https://api.slen.ai'): ApiClient
    {
        $psr17Factory = new Psr17Factory();

        $httpContext = new HttpContext(new Psr18Transport($http), $psr17Factory, $psr17Factory);

        return new ApiClient($httpContext, $baseUrl, new HttpJournal(new Journal(new NullLogger())));
    }
}
