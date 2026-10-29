<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Iterator;
use PhpToken;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Slen\Sdk\Laravel\Slen;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;
use Slen\Sdk\Laravel\SlenServiceProvider;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Testing\RecordingSpanRecorder;

/**
 * The examples of the console's help section — `examples/help/<id>/` — run
 * as they are written.
 *
 * An article never carries SDK code of its own: it points at a region of an
 * example, and this suite (with `test/help-examples.test.ts` of the Node.js SDK
 * for its files) is what makes that code true. Each `php.php` is included once.
 * It builds its client for real — `SlenSdk::create(new SlenConfig(…))`, which
 * does not touch the network — and hands it to `$offline`, which answers with
 * the same SDK on a `FakeHttpClient` fed from the example's `example.json`: its
 * config with another PSR-18 client (`SlenConfig::withHttpClient()`), so the key,
 * the logger and the span recorder stay the example's. What the example then
 * put on the wire and what it printed are held against `expect.json`. One call more, one call less, another key, another body — each
 * is red.
 *
 * `expect.json` is the same file for both languages, so the two examples of
 * one id send the same requests and print the same lines. The checks that only
 * read the files of this package are `HelpExampleFilesTest`; the JSON of the
 * examples is held against the OpenAPI spec where the contract is kept.
 *
 * An example marked `"laravel": true` gets its SDK the way an application
 * does — from the container `SlenServiceProvider` registered into, or through
 * the `Slen` facade — and that SDK is put on the same double.
 *
 * The rules an example follows are in `AGENTS.md` ("Help examples"). The examples sit
 * where `Paths::EXAMPLES` says: run the suite with that directory mounted.
 */
class HelpExamplesTest extends TestCase
{
    /**
     * The environment of the application a Laravel example runs in: what
     * `config/slen-sdk.php` reads its keys from. The harness's key and nothing
     * else — whatever the machine has set, the rest of the keys hold their
     * defaults. Read by the probe `env()` the config file is evaluated with.
     */
    public const APPLICATION_ENVIRONMENT = [self::KEY_VARIABLE => self::KEY];

    private const EXAMPLES = Paths::EXAMPLES.'/help';

    // What every example is written against, so that none leans on a default.
    private const BASE_URL = 'https://api.slen.ai';

    private const KEY_VARIABLE = 'SLEN_API_KEY';

    private const KEY = 'sl_live_help_example';

    private const CONFIG_FILE = __DIR__.'/../config/slen-sdk.php';

    private const CONFIG_HEADER = "<?php\n\ndeclare(strict_types=1);\n";

    // The namespace `config/slen-sdk.php` is evaluated in: its unqualified `env()` is the probe declared there.
    private const CONFIG_PROBE = 'Slen\\Sdk\\Tests\\HelpExamplesConfigProbe';

    // An example that forgot `$offline` would send its request for real. While
    // one runs, every proxy variable Guzzle and cURL read points at a port
    // nothing listens on: such a request fails at once instead of leaving.
    private const PROXY_VARIABLES = ['HTTP_PROXY', 'HTTPS_PROXY', 'http_proxy', 'https_proxy'];

    private const NOWHERE = 'http://127.0.0.1:9';

    // The list of hosts that go around the proxy is emptied for the same run:
    // with `NO_PROXY=*` in the environment the dead port would not be asked.
    private const PROXY_EXEMPTIONS = ['NO_PROXY', 'no_proxy'];

    private const CALL = [
        'method' => 'POST',
        'path' => '/v1/operations/execute',
        'headers' => ['Idempotency-Key' => 'order-42:email'],
        'body' => ['operation' => 'generate_email', 'input' => ['messages' => []]],
    ];

    private const REQUEST = [
        'method' => 'POST',
        'url' => self::BASE_URL.'/api/v1/operations/execute',
        'headers' => ['Authorization' => 'Bearer '.self::KEY, 'Idempotency-Key' => 'order-42:email'],
        'json' => ['input' => ['messages' => []], 'operation' => 'generate_email'],
    ];

    private const READ = [
        'method' => 'GET',
        'url' => self::BASE_URL.'/api/v1/executions/7/status',
        'headers' => ['Authorization' => 'Bearer '.self::KEY],
        'json' => null,
    ];

    private const BEARER = 'Bearer '.self::KEY;

    #[DataProvider('exampleProvider')]
    public function testAnExampleMakesTheCallsItIsExpectedToMakeAndPrintsWhatItIsExpectedToPrint(string $id): void
    {
        $example = self::json($id, 'example.json');
        $expectations = self::json($id, 'expect.json');
        $outcome = self::runExample($id, $example['responses'], ($example['laravel'] ?? false) === true);

        if ($outcome['handed'] === null) {
            self::assertCount(count($example['responses']), $expectations['calls'], 'one answer in example.json for each call of expect.json');
            self::assertSame([], self::differences($expectations['calls'], $outcome['wire'], self::BEARER));
        } else {
            // The example brought its own double: nothing may have reached the
            // wire, and what the double recorded is what the expectations describe.
            self::assertSame([], $example['responses'], 'an example with its own double is served nothing by the harness');
            self::assertSame([], $outcome['wire']);
            self::assertSame([], self::differences($expectations['calls'], $outcome['handed'], null));
        }

        self::assertSame($expectations['prints'], $outcome['printed']);
    }

    /**
     * The twin of `elseLines()` in the TypeScript suite: a region branches with
     * `match (true)` or with independent `if`s, so an article never shows an
     * `else`.
     */
    #[DataProvider('exampleProvider')]
    public function testAnExampleBranchesWithoutElse(string $id): void
    {
        $lines = [];

        foreach (PhpToken::tokenize((string) file_get_contents(self::EXAMPLES.'/'.$id.'/php.php')) as $token) {
            if ($token->is([T_ELSE, T_ELSEIF]) === true) {
                $lines[] = $token->line;
            }
        }

        self::assertSame([], $lines, $id.'/php.php: no else and no elseif — match (true) or independent ifs');
    }

    public function testNothingIsSaidAboutTheCallsThatWereExpected(): void
    {
        $read = ['method' => 'GET', 'path' => '/v1/executions/7/status'];

        self::assertSame([], self::differences([self::CALL, $read], [self::REQUEST, self::READ], self::BEARER));
    }

    public function testACallMoreThanExpectedIsReported(): void
    {
        self::assertSame(
            ['call #2 was not expected: GET '.self::BASE_URL.'/api/v1/executions/7/status'],
            self::differences([self::CALL], [self::REQUEST, self::READ], self::BEARER),
        );
    }

    public function testACallLessThanExpectedIsReported(): void
    {
        self::assertSame(
            ['call #2 was never made: GET /v1/executions/7/status'],
            self::differences([self::CALL, ['method' => 'GET', 'path' => '/v1/executions/7/status']], [self::REQUEST], self::BEARER),
        );
    }

    public function testAnotherMethodHostOrPathIsReported(): void
    {
        $local = ['url' => 'http://localhost:8080/api/v1/operations/execute'] + self::REQUEST;

        self::assertSame(
            ['call #1 is POST http://localhost:8080/api/v1/operations/execute, expected POST '.self::BASE_URL.'/api/v1/operations/execute'],
            self::differences([self::CALL], [$local], self::BEARER),
        );
        self::assertCount(1, self::differences([['method' => 'PUT'] + self::CALL], [self::REQUEST], self::BEARER));
    }

    // A key the SDK generated is random: it can be neither expected nor absent,
    // so an example that does not show its own key cannot be green.
    public function testAKeyThatIsNotTheOneTheExampleShowsIsReported(): void
    {
        $generated = self::REQUEST;
        $generated['headers']['Idempotency-Key'] = '0b0e6d5e-7d0b-4f8e-9d43-0a1f0c5f2f11';
        $keyless = ['method' => 'POST', 'path' => self::CALL['path'], 'body' => self::CALL['body']];

        self::assertSame(
            ['call #1 sends Idempotency-Key: 0b0e6d5e-7d0b-4f8e-9d43-0a1f0c5f2f11, expected order-42:email'],
            self::differences([self::CALL], [$generated], self::BEARER),
        );
        self::assertSame(
            ['call #1 sends Idempotency-Key: order-42:email, and none was expected'],
            self::differences([$keyless], [self::REQUEST], self::BEARER),
        );
    }

    public function testARequestWithoutTheKeyTheExampleWasGivenIsReported(): void
    {
        $other = self::REQUEST;
        $other['headers']['Authorization'] = 'Bearer ';

        self::assertSame(
            ['call #1 sends Authorization: Bearer , expected '.self::BEARER],
            self::differences([self::CALL], [$other], self::BEARER),
        );
        // An example that brought its own double chose its own key.
        self::assertSame([], self::differences([self::CALL], [$other], null));
    }

    public function testAnotherBodyOrQueryIsReported(): void
    {
        $more = self::REQUEST;
        $more['json']['context'] = ['plan' => 'premium'];
        $listed = ['url' => self::BASE_URL.'/api/v1/customers?limit=2'] + self::READ;

        self::assertCount(1, self::differences([self::CALL], [$more], self::BEARER));
        self::assertSame(
            ['call #1 sends the body [], expected null'],
            self::differences([['method' => 'GET', 'path' => '/v1/executions/7/status']], [['json' => []] + self::READ], self::BEARER),
        );
        self::assertSame(
            [],
            self::differences([['method' => 'GET', 'path' => '/v1/customers', 'query' => ['limit' => '2']]], [$listed], self::BEARER),
        );
        self::assertSame(
            ['call #1 has the query {"limit":"2"}, expected []'],
            self::differences([['method' => 'GET', 'path' => '/v1/customers']], [$listed], self::BEARER),
        );
    }

    // `$offline` changes the wire and nothing else: an example that gave its
    // SDK a span recorder still has its spans, under the settings it wrote.
    public function testTheSdkHandedBackIsTheOneTheExampleBuiltOnTheDouble(): void
    {
        $wire = new FakeHttpClient()->pushJson(200, ['data' => [
            'execution_id' => '900000000000000001',
            'status' => 'completed',
            'operation' => 'generate_email',
            'output' => null,
            'error' => null,
            'error_code' => null,
            'error_message' => null,
            'cost' => ['estimated' => '0.02700000', 'actual' => null],
            'cache' => ['hit' => false, 'cached_at' => null],
            'replayed' => false,
            'waiting_on' => null,
            'trace_url' => '/v1/executions/900000000000000001',
        ]]);
        $spans = new RecordingSpanRecorder();
        $built = SlenSdk::create(new SlenConfig(apiKey: 'key-of-the-example', baseUrl: 'https://example.test', spanRecorder: $spans));

        $slen = self::offline($wire)($built);
        $slen->operations()->execute('generate_email', ['messages' => []], new ExecuteOptions(idempotencyKey: 'order-42:email'));

        self::assertInstanceOf(SlenSdk::class, $slen);
        self::assertNotSame($built, $slen);
        self::assertSame('https://example.test/api/v1/operations/execute', (string) $wire->lastRequest()->getUri());
        self::assertSame(
            ['method' => 'POST', 'url' => 'https://example.test/api/v1/operations/execute', 'json' => ['operation' => 'generate_email', 'input' => ['messages' => []]]],
            array_diff_key(self::sent($wire->lastRequest()), ['headers' => true]),
        );
        self::assertSame('Bearer key-of-the-example', $wire->lastRequest()->getHeaderLine('Authorization'));
        self::assertSame('operations.execute', $spans->last()->operation);
    }

    // The run of an example may not lean on what the machine has set: the key
    // is the harness's, every proxy is the dead port, and no host is exempt
    // from it — and all of it is put back afterwards.
    public function testAnExampleRunsInAnEnvironmentOfItsOwnAndLeavesNoneBehind(): void
    {
        $read = static function (): array {
            return [
                getenv(self::KEY_VARIABLE),
                getenv('HTTPS_PROXY'),
                $_SERVER['https_proxy'] ?? null,
                getenv('NO_PROXY'),
                $_SERVER['no_proxy'] ?? null,
            ];
        };
        $found = [getenv('NO_PROXY'), $_SERVER['no_proxy'] ?? null];

        putenv('NO_PROXY=*');
        $_SERVER['no_proxy'] = '*';

        try {
            $before = $read();

            self::assertSame([self::KEY, self::NOWHERE, self::NOWHERE, '', ''], self::inTheEnvironmentOfAnExample($read));
            self::assertSame($before, $read());
            self::assertSame('*', getenv('NO_PROXY'));
        } finally {
            putenv($found[0] === false ? 'NO_PROXY' : 'NO_PROXY='.$found[0]);

            if ($found[1] === null) {
                unset($_SERVER['no_proxy']);
            } else {
                $_SERVER['no_proxy'] = $found[1];
            }
        }
    }

    // A Laravel example gets its SDK from the application: the one the provider builds — the key of the
    // application's environment, the hosted API — on the double, the same through the container, the facade and
    // `app()`. Afterwards the container and the facades are the next test's again.
    public function testALaravelExampleGetsTheSdkOfTheApplicationOnTheDouble(): void
    {
        $health = ['app' => 'ok', 'release' => null, 'server' => 'ok', 'mysql' => 'ok', 'redis' => 'ok', 'queue' => null, 'broadcast' => null,
            'opcache_preloaded_files' => null, 'opcache_cached_scripts' => null];
        $wire = new FakeHttpClient()->pushJson(200, $health);

        [$resolved, $behindTheFacade, $ofTheHelper] = self::inALaravelApplication(
            self::offline($wire),
            static function (): array {
                return [
                    Container::getInstance()->make(SlenSdk::class),
                    Slen::getFacadeRoot(),
                    call_user_func('app', SlenSdk::class),
                ];
            },
        );

        self::assertInstanceOf(SlenSdk::class, $resolved);
        self::assertSame($resolved, $behindTheFacade);
        self::assertSame($resolved, $ofTheHelper);
        self::assertSame('ok', $resolved->health()->app);
        self::assertSame(self::BASE_URL.'/api/health', (string) $wire->lastRequest()->getUri());
        self::assertSame(self::BEARER, $wire->lastRequest()->getHeaderLine('Authorization'));
        self::assertNotInstanceOf(CachesConfiguration::class, Container::getInstance());
        self::assertNull(Facade::getFacadeApplication());
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function exampleProvider(): Iterator
    {
        if (is_dir(self::EXAMPLES) === false) {
            throw new RuntimeException(
                'examples/help is not where tests/Paths.php says it is: mount that directory too.',
            );
        }

        foreach (scandir(self::EXAMPLES) as $id) {
            if ($id[0] === '.' || is_dir(self::EXAMPLES.'/'.$id) === false) {
                continue;
            }

            if (in_array('php', self::json($id, 'example.json')['languages'], true) === true) {
                yield $id => [$id];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $id, string $name): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(self::EXAMPLES.'/'.$id.'/'.$name), true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * Runs `php.php` of one example — inside a Laravel application when the example says so.
     *
     * @param list<array{status: int, body: array<string, mixed>}> $answers
     *
     * @return array{
     *     wire: list<array{method: string, url: string, headers: array<string, string>, json: array<string, mixed>|null}>,
     *     handed: list<array{method: string, url: string, headers: array<string, string>, json: array<string, mixed>|null}>|null,
     *     printed: list<string>,
     * } `wire` is what the harness's own transport was asked for; `handed` is what the example's own double
     *   recorded, when it brought one and left it in `$recorded`; `printed` is every line it wrote to the output
     */
    private static function runExample(string $id, array $answers, bool $laravel = false): array
    {
        $wire = new FakeHttpClient();

        foreach ($answers as $answer) {
            $wire->pushJson($answer['status'], $answer['body']);
        }

        $offline = self::offline($wire);
        $file = self::EXAMPLES.'/'.$id.'/php.php';

        ob_start();

        try {
            $defined = self::inTheEnvironmentOfAnExample(
                static function () use ($laravel, $offline, $file): array {
                    return $laravel === true
                        ? self::inALaravelApplication($offline, static function () use ($file, $offline): array {
                            return self::evaluate($file, $offline);
                        })
                        : self::evaluate($file, $offline);
                },
            );
        } finally {
            $printed = (string) ob_get_clean();
        }

        $handed = $defined['recorded'] ?? null;

        self::assertTrue(
            $handed === null || is_array($handed),
            '`$recorded` is what the example hands over of its own double: the list of its requests.',
        );

        return [
            'wire' => array_map(self::sent(...), $wire->requests()),
            'handed' => $handed === null ? null : array_map(static function (mixed $request): mixed {
                return $request instanceof RequestInterface ? self::sent($request) : $request;
            }, $handed),
            'printed' => $printed === '' ? [] : explode("\n", rtrim($printed, "\n")),
        ];
    }

    /**
     * Runs something in the environment an example is given — the harness's
     * key, every proxy at the dead port, no host exempt from it — and puts back
     * what was there. Both places a variable is read from are set: `getenv()`
     * (the example's key, cURL's proxies) and `$_SERVER`, which Guzzle looks at
     * first.
     *
     * @template T
     *
     * @param Closure(): T $run
     *
     * @return T
     */
    private static function inTheEnvironmentOfAnExample(Closure $run): mixed
    {
        $environment = [self::KEY_VARIABLE => self::KEY]
            + array_fill_keys(self::PROXY_VARIABLES, self::NOWHERE)
            + array_fill_keys(self::PROXY_EXEMPTIONS, '');
        $before = [];

        foreach ($environment as $name => $value) {
            $before[$name] = [getenv($name), $_SERVER[$name] ?? null];
            putenv($name.'='.$value);
            $_SERVER[$name] = $value;
        }

        try {
            return $run();
        } finally {
            foreach ($before as $name => [$process, $server]) {
                putenv($process === false ? $name : $name.'='.$process);

                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    /**
     * What an example calls as `$offline`: the SDK it built, on the double.
     * Everything else the example gave it stays — the `SlenConfig` with its key,
     * logger and span recorder gets another PSR-18 client and nothing else — so
     * the wire is the one thing that changes.
     *
     * @return Closure(SlenSdk): SlenSdk
     */
    private static function offline(FakeHttpClient $wire): Closure
    {
        return static function (SlenSdk $slen) use ($wire): SlenSdk {
            $psr17Factory = new Psr17Factory();

            return SlenSdk::create($slen->config()->withHttpClient($wire, $psr17Factory, $psr17Factory));
        };
    }

    /**
     * A request of SlenSdk as the comparison reads one: method, URL, headers, the body decoded.
     *
     * @return array{method: string, url: string, headers: array<string, string>, json: array<string, mixed>|null}
     */
    private static function sent(RequestInterface $request): array
    {
        $headers = [];

        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[(string) $name] = $request->getHeaderLine((string) $name);
        }

        $body = (string) $request->getBody();

        return [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $headers,
            'json' => $body === '' ? null : json_decode($body, true, 64, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * The example in a scope of its own, with `$offline` in it; answers with
     * every variable the example left behind.
     *
     * @param Closure(SlenSdk): SlenSdk $offline
     *
     * @return array<string, mixed>
     */
    private static function evaluate(string $exampleFile, Closure $offline): array
    {
        return (static function () use ($exampleFile, $offline): array {
            include $exampleFile;

            return get_defined_vars();
        })();
    }

    /**
     * Runs something inside the Laravel application a `"laravel": true`
     * example is given, and takes the application away afterwards: the
     * container instance and the facades are the next test's again.
     *
     * @template T
     *
     * @param Closure(SlenSdk): SlenSdk $offline
     * @param Closure(): T $run
     *
     * @return T
     */
    private static function inALaravelApplication(Closure $offline, Closure $run): mixed
    {
        self::laravelApplication($offline);

        try {
            return $run();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Container::setInstance(null);
        }
    }

    /**
     * An application as far as the SDK sees one: a container with the `slen-sdk`
     * configuration, `SlenServiceProvider` registered into it, and the SDK it
     * builds put on the double — the provider's `SlenConfig`, another wire
     * (`$offline`, through `extend()`, so the provider's own binding is the one
     * resolved). The facades and `Container::getInstance()` answer from it.
     *
     * The configuration counts as cached, so `mergeConfigFrom()` leaves the file
     * alone: loading it would run the framework's `env()`, which needs the
     * Dotenv repository of a booted application. The values are what the file
     * gives in the application's environment instead (`configOfTheApplication()`).
     *
     * @param Closure(SlenSdk): SlenSdk $offline
     */
    private static function laravelApplication(Closure $offline): Container
    {
        $application = new class () extends Container implements CachesConfiguration {
            public function configurationIsCached(): bool
            {
                return true;
            }

            public function getCachedConfigPath(): string
            {
                return '';
            }

            public function getCachedServicesPath(): string
            {
                return '';
            }
        };

        $application->instance('config', self::configRepository(['slen-sdk' => self::configOfTheApplication()]));
        new SlenServiceProvider($application)->register();
        $application->extend(SlenSdk::class, static function (SlenSdk $slenSdk) use ($offline): SlenSdk {
            return $offline($slenSdk);
        });

        self::declareTheHelpersOfTheFramework();
        Container::setInstance($application);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);

        return $application;
    }

    /**
     * `config/slen-sdk.php` as it reads in the application's environment
     * (`APPLICATION_ENVIRONMENT`): evaluated in a namespace of its own, where
     * its unqualified `env()` calls resolve to a probe — the way
     * `ConfigFileTest` reads it.
     *
     * @return array<string, mixed>
     */
    private static function configOfTheApplication(): array
    {
        $source = (string) file_get_contents(self::CONFIG_FILE);

        self::assertStringStartsWith(self::CONFIG_HEADER, $source);

        if (function_exists(self::CONFIG_PROBE.'\\env') === false) {
            eval('namespace '.self::CONFIG_PROBE.'; function env(string $key, mixed $default = null): mixed {'
                .' return \\'.self::class.'::APPLICATION_ENVIRONMENT[$key] ?? $default; }');
        }

        /** @var array<string, mixed> */
        return eval('namespace '.self::CONFIG_PROBE.'; '.substr($source, strlen(self::CONFIG_HEADER)));
    }

    /**
     * The configuration of the application: the items, read by dotted keys.
     *
     * @param array<string, mixed> $items
     */
    private static function configRepository(array $items): Repository
    {
        return new class ($items) implements Repository {
            /**
             * @param array<string, mixed> $items
             */
            public function __construct(private array $items)
            {
            }

            public function has($key): bool
            {
                return Arr::has($this->items, $key);
            }

            public function get($key, $default = null): mixed
            {
                return Arr::get($this->items, $key, $default);
            }

            /**
             * @return array<string, mixed>
             */
            public function all(): array
            {
                return $this->items;
            }

            public function set($key, $value = null): void
            {
                foreach (is_array($key) === true ? $key : [$key => $value] as $name => $given) {
                    Arr::set($this->items, $name, $given);
                }
            }

            public function prepend($key, $value): void
            {
                $this->set($key, [$value, ...(array) $this->get($key, [])]);
            }

            public function push($key, $value): void
            {
                $this->set($key, [...(array) $this->get($key, []), $value]);
            }
        };
    }

    /**
     * `app()` is a helper of laravel/framework. The suite installs the
     * illuminate/* packages the SDK suggests and not the framework, so an
     * application's helper is declared here — as the framework declares it —
     * for the code that calls it: an example resolves the SDK through it.
     */
    private static function declareTheHelpersOfTheFramework(): void
    {
        if (function_exists('app') === false) {
            eval('function app($abstract = null, array $parameters = []) {'
                .' return $abstract === null ? \\Illuminate\\Container\\Container::getInstance()'
                .' : \\Illuminate\\Container\\Container::getInstance()->make($abstract, $parameters); }');
        }
    }

    /**
     * Every way the requests differ from the expected calls, in words; empty
     * when they are the same. `$authorization` is what every request has to
     * carry — the key the harness put in the environment — or null when the
     * example was not given one (it brought a double and a key of its own).
     *
     * The `Idempotency-Key` is compared both ways: a call that is expected to
     * carry one has to carry exactly that, and a call that is not may carry
     * none.
     *
     * @param array<int, array<string, mixed>> $expected
     * @param array<int, array<string, mixed>> $requests
     *
     * @return list<string>
     */
    private static function differences(array $expected, array $requests, ?string $authorization): array
    {
        $found = [];

        for ($index = 0; $index < max(count($expected), count($requests)); ++$index) {
            $call = $expected[$index] ?? null;
            $request = $requests[$index] ?? null;
            $at = 'call #'.($index + 1);

            if ($call === null && $request !== null) {
                $found[] = sprintf('%s was not expected: %s %s', $at, $request['method'], $request['url']);
            } elseif ($call !== null && $request === null) {
                $found[] = sprintf('%s was never made: %s %s', $at, $call['method'], $call['path']);
            } elseif ($call !== null && $request !== null) {
                foreach (self::differencesOfOne($call, $request, $authorization) as $difference) {
                    $found[] = $at.' '.$difference;
                }
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $call
     * @param array<string, mixed> $request
     *
     * @return list<string>
     */
    private static function differencesOfOne(array $call, array $request, ?string $authorization): array
    {
        $found = [];
        [$address, $queryString] = explode('?', $request['url'], 2) + [1 => ''];
        parse_str($queryString, $query);
        $headers = $call['headers'] ?? [];
        $sent = array_change_key_case($request['headers']);

        if ($authorization !== null) {
            $headers += ['Authorization' => $authorization];
        }

        $expectedAddress = self::BASE_URL.'/api'.$call['path'];

        if ($request['method'] !== $call['method'] || $address !== $expectedAddress) {
            $found[] = sprintf('is %s %s, expected %s %s', $request['method'], $address, $call['method'], $expectedAddress);
        }

        if (self::canonical($query) !== self::canonical($call['query'] ?? [])) {
            $found[] = sprintf('has the query %s, expected %s', self::canonical($query), self::canonical($call['query'] ?? []));
        }

        foreach ($headers as $name => $value) {
            $given = $sent[strtolower($name)] ?? null;

            if ($given !== $value) {
                $found[] = sprintf('sends %s: %s, expected %s', $name, $given ?? '(nothing)', $value);
            }
        }

        $expectsKey = isset(array_change_key_case($headers)['idempotency-key']);

        if ($expectsKey === false && isset($sent['idempotency-key']) === true) {
            $found[] = sprintf('sends Idempotency-Key: %s, and none was expected', $sent['idempotency-key']);
        }

        if (self::canonical($request['json']) !== self::canonical($call['body'] ?? null)) {
            $found[] = sprintf('sends the body %s, expected %s', self::canonical($request['json']), self::canonical($call['body'] ?? null));
        }

        return $found;
    }

    // Stable text of a JSON value: the keys of every object in one order. An
    // empty object and an empty list are the same `[]` here — PHP has one
    // empty array for both, on the wire and in what `json_decode()` gives.
    private static function canonical(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sorted(mixed $value): mixed
    {
        if (is_array($value) === false) {
            return $value;
        }

        if (array_is_list($value) === false) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
