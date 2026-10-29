<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Transport;

use ArrayObject;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slen\Sdk\Exceptions\CustomerNotFoundException;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

final class GuzzleTransportTest extends TestCase
{
    /** @var ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> */
    private ArrayObject $history;

    protected function setUp(): void
    {
        $this->history = new ArrayObject();
    }

    public function testTheTimeoutOfACallReachesGuzzleForThatCallOnly(): void
    {
        $logger = new RecordingLogger();
        $slenSdk = $this->sdk(['timeout' => 9.0], $logger, $this->customer(), $this->customer());

        $slenSdk->customers()->get('user_8421', new RequestOptions(timeout: 3.5));
        $slenSdk->customers()->get('user_8421');

        self::assertSame([3.5, 9.0], [$this->option(0, 'timeout'), $this->option(1, 'timeout')]);
        self::assertNotContains('warning slen.config.timeout_not_applied', $logger->events());
    }

    public function testGuzzleIsAskedAsPsr18AsksIt(): void
    {
        $this->sdk([], new RecordingLogger(), $this->customer())->customers()->get('user_8421', new RequestOptions(timeout: 3.5));

        self::assertFalse($this->option(0, 'http_errors'));
        self::assertFalse($this->option(0, 'allow_redirects'));
    }

    public function testAStatusIsTheAnswerOfTheCallNotAnErrorOfGuzzle(): void
    {
        $missing = new Response(404, ['Content-Type' => 'application/json'], json_encode(Answers::refusal('customer_not_found'), JSON_THROW_ON_ERROR));

        $this->expectException(CustomerNotFoundException::class);

        $this->sdk([], new RecordingLogger(), $missing)->customers()->get('user_8421', new RequestOptions(timeout: 3.5));
    }

    private function option(int $request, string $name): mixed
    {
        return $this->history[$request]['options'][$name] ?? null;
    }

    private function customer(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => CustomerAnswers::customer()], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{timeout?: float} $config
     */
    private function sdk(array $config, RecordingLogger $logger, Response ...$answers): SlenSdk
    {
        $handlerStack = HandlerStack::create(new MockHandler(array_values($answers)));
        $container = $this->history;
        $handlerStack->push(Middleware::history($container));
        $guzzleClient = new GuzzleClient(['handler' => $handlerStack, ...$config]);

        return SlenSdk::create(new SlenConfig(Answers::KEY, retries: 0, logger: $logger, httpClient: $guzzleClient));
    }
}
