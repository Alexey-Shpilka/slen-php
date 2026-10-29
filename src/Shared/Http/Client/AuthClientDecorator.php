<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Client;

use Composer\InstalledVersions;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;

/** @internal */
final readonly class AuthClientDecorator implements ApiClientInterface
{
    private const string PACKAGE = 'slen/sdk';

    private string $userAgent;

    public function __construct(
        private ApiClientInterface $apiClient,
        #[\SensitiveParameter]
        private string $apiKey,
        ?string $userAgent = null,
    ) {
        $this->userAgent = $userAgent ?? $this->ownUserAgent();
    }

    public function send(ApiRequestDTO $request): ApiResponseDTO
    {
        return $this->apiClient->send(
            $request
                ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
                ->withHeader('User-Agent', $this->userAgent),
        );
    }

    private function ownUserAgent(): string
    {
        $version = null;

        if (class_exists(InstalledVersions::class) === true && InstalledVersions::isInstalled(self::PACKAGE) === true) {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
        }

        return \sprintf('slen-sdk-php/%s php/%s', $version ?? 'unknown', PHP_VERSION);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['userAgent' => $this->userAgent];
    }
}
