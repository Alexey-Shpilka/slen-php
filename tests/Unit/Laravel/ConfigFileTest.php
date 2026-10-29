<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Laravel;

use PHPUnit\Framework\TestCase;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\Tests\Unit\Support\SourceTree;

/**
 * config/slen-sdk.php, evaluated in a namespace of its own where its env() is a probe: the suite has no
 * framework, and the framework's env() needs one. The probe answers from $environment and keeps what it was asked.
 */
final class ConfigFileTest extends TestCase
{
    private const string FILE = __DIR__ . '/../../../config/slen-sdk.php';

    private const string HEADER = "<?php\n\ndeclare(strict_types=1);\n";

    private const string PROBE = __NAMESPACE__ . '\\ConfigFileProbe';

    private const array VARIABLES = [
        'SLEN_API_KEY',
        'SLEN_TIMEOUT',
        'SLEN_CONNECT_TIMEOUT',
        'SLEN_RETRIES',
        'SLEN_LOG_CHANNEL',
        'SLEN_SPANS',
        'SLEN_SPAN_LEVEL',
        'SLEN_WEBHOOK_SECRET',
        'SLEN_WEBHOOK_TOLERANCE',
    ];

    /** @var array<string, string> */
    public static array $environment = [];

    /** @var list<string> */
    public static array $asked = [];

    public function testWithNothingSetTheKeysAreTheDefaultsOfTheSdk(): void
    {
        self::assertSame([
            'api_key' => null,
            'timeout' => SlenConfig::DEFAULT_TIMEOUT,
            'connect_timeout' => SlenConfig::DEFAULT_CONNECT_TIMEOUT,
            'retries' => SlenConfig::DEFAULT_RETRIES,
            'log_channel' => null,
            'spans' => 'null',
            'span_level' => 'info',
            'webhook_secret' => null,
            'webhook_tolerance' => SlenConfig::DEFAULT_WEBHOOK_TOLERANCE_SECONDS,
        ], self::load([]));
    }

    public function testEveryKeyIsReadFromItsVariable(): void
    {
        self::assertSame([
            'api_key' => 'sk_live_key',
            'timeout' => 0.5,
            'connect_timeout' => 0.25,
            'retries' => 4,
            'log_channel' => 'slen',
            'spans' => 'log',
            'span_level' => 'debug',
            'webhook_secret' => 'whsec_key',
            'webhook_tolerance' => 60,
        ], self::load([
            'SLEN_API_KEY' => 'sk_live_key',
            'SLEN_TIMEOUT' => '0.5',
            'SLEN_CONNECT_TIMEOUT' => '0.25',
            'SLEN_RETRIES' => '4',
            'SLEN_LOG_CHANNEL' => 'slen',
            'SLEN_SPANS' => 'log',
            'SLEN_SPAN_LEVEL' => 'debug',
            'SLEN_WEBHOOK_SECRET' => 'whsec_key',
            'SLEN_WEBHOOK_TOLERANCE' => '60',
        ]));
    }

    /**
     * The address is the SDK's constant, so the file an application publishes has no key for it: a test or a
     * local stack sets `slen-sdk.base_url` itself, and the provider honours it (SlenServiceProviderTest).
     */
    public function testTheFileAsksForItsSlenVariablesOnlyAndNamesNoAddress(): void
    {
        self::load([]);
        $source = (string) file_get_contents(self::FILE);

        self::assertSame(self::VARIABLES, self::$asked);
        self::assertSame(\count(self::VARIABLES), preg_match_all('/env\(/', $source), 'every variable is asked once, by its own name');
        self::assertStringNotContainsString('localhost', $source);
        self::assertStringNotContainsString(SlenConfig::DEFAULT_BASE_URL, $source, "the default address is the SDK's alone");
        self::assertDoesNotMatchRegularExpression('/base_?url/i', $source, 'the published file names no address');
    }

    /**
     * The config file is the only place the environment is read: the classes take what they are given.
     */
    public function testNoClassOfTheSdkReadsTheEnvironment(): void
    {
        $readers = [];

        foreach ((new SourceTree())->files() as $file => $code) {
            if (preg_match('/\bgetenv\s*\(|\$_ENV\b|\$_SERVER\b|\benv\s*\(|\bputenv\s*\(/', $code) === 1) {
                $readers[] = $file;
            }
        }

        self::assertSame([], $readers);
    }

    public function testNoFileOfThePackageIsLoadedBesideItsClasses(): void
    {
        /** @var array{autoload: array<string, mixed>} $composer */
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['psr-4'], array_keys($composer['autoload']));
        self::assertFileDoesNotExist(__DIR__ . '/../../../src/compat.php');
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    private static function load(array $environment): array
    {
        self::$environment = $environment;
        self::$asked = [];

        $source = (string) file_get_contents(self::FILE);

        self::assertStringStartsWith(self::HEADER, $source);

        if (!\function_exists(self::PROBE . '\\env')) {
            eval(\sprintf(
                'namespace %s; function env(string $key, mixed $value = null): mixed { \\%s::$asked[] = $key; return \\%2$s::$environment[$key] ?? $value; }',
                self::PROBE,
                self::class,
            ));
        }

        $config = eval('namespace ' . self::PROBE . '; ' . substr($source, \strlen(self::HEADER)));
        self::assertIsArray($config);

        /** @var array<string, mixed> $config */
        return $config;
    }
}
