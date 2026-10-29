<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Client;

use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Slen\Sdk\LogMessages;
use Slen\Sdk\Shared\Http\Transport\GuzzleTransport;
use Slen\Sdk\Shared\Http\Transport\Psr18Transport;
use Slen\Sdk\Shared\Http\Transport\SymfonyTransport;
use Slen\Sdk\Shared\Http\Transport\TransportInterface;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\SlenConfig;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/** @internal */
final readonly class HttpClientFactory
{
    /** @var Closure(string): bool */
    private Closure $classExists;

    /** @var Closure(string, string): bool */
    private Closure $methodExists;

    /**
     * @param (Closure(string): bool)|null         $classExists
     * @param (Closure(string, string): bool)|null $methodExists
     */
    public function __construct(?Closure $classExists = null, ?Closure $methodExists = null)
    {
        $this->classExists = $classExists ?? class_exists(...);
        $this->methodExists = $methodExists ?? method_exists(...);
    }

    /** @throws LogicException when no PSR-18 client or PSR-17 factory is installed */
    public function create(SlenConfig $config, Journal $journal): HttpContext
    {
        try {
            return new HttpContext(
                $config->httpClient instanceof ClientInterface ? $this->given($config->httpClient) : $this->built($config, $journal),
                $config->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
                $config->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            );
        } catch (DiscoveryException $discoveryException) {
            throw new LogicException(
                'Slen: no PSR-18 client or PSR-17 factory is installed; require guzzlehttp/guzzle, or symfony/http-client with nyholm/psr7.',
                0,
                $discoveryException,
            );
        }
    }

    private function given(ClientInterface $client): TransportInterface
    {
        if ($client instanceof GuzzleClientInterface) {
            return new GuzzleTransport($client);
        }

        if ($client instanceof Psr18Client) {
            return $this->symfony($client);
        }

        return new Psr18Transport($client);
    }

    private function built(SlenConfig $config, Journal $journal): TransportInterface
    {
        if (($this->classExists)(GuzzleClient::class) === true) {
            return new GuzzleTransport(new GuzzleClient([
                'timeout' => $config->timeout,
                'connect_timeout' => $config->connectTimeout,
                'http_errors' => false,
            ]));
        }

        if (($this->classExists)(Psr18Client::class) === true) {
            return $this->symfony(new Psr18Client(HttpClient::create([
                'timeout' => $config->timeout,
                'max_duration' => $config->timeout,
            ])));
        }

        $client = Psr18ClientDiscovery::find();
        $journal->warning(LogMessages::CONFIG_TIMEOUT_NOT_APPLIED, ['client' => $client::class]);

        return new Psr18Transport($client);
    }

    private function symfony(Psr18Client $client): TransportInterface
    {
        if ($this->takesOptionsPerCall() === true) {
            return new SymfonyTransport($client);
        }

        return new Psr18Transport($client);
    }

    private function takesOptionsPerCall(): bool
    {
        // Psr18Client::withOptions() came with symfony/http-client 6.2; an older one keeps the timeouts it was built with.
        return ($this->methodExists)(Psr18Client::class, 'withOptions');
    }
}
