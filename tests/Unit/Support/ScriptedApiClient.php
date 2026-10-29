<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use LogicException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Shared\Http\Client\ApiClientInterface;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;

/**
 * A link of the HTTP chain that plays its outcomes in order and keeps the requests it was given.
 */
final class ScriptedApiClient implements ApiClientInterface
{
    /** @var list<ApiRequestDTO> */
    public array $requests = [];

    /** @var list<ApiResponseDTO|SlenException> */
    private array $outcomes;

    public function __construct(ApiResponseDTO|SlenException ...$outcomes)
    {
        $this->outcomes = array_values($outcomes);
    }

    public function send(ApiRequestDTO $request): ApiResponseDTO
    {
        $this->requests[] = $request;
        $next = array_shift($this->outcomes) ?? throw new LogicException('No outcome is scripted for ' . $request->describe());

        if ($next instanceof SlenException) {
            throw $next;
        }

        return $next->attempted($request->attempt, false);
    }
}
