<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests;

use FilesystemIterator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use Slen\Sdk\Laravel\SlenServiceProvider;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;

/**
 * The help slice of this package — `help-slice.json` — is
 * what the code says today.
 *
 * The console's Help & Docs section prints the parameters of `SlenConfig` and the
 * keys of the config file, names methods in prose and anchors articles to
 * symbols, and it reads all of that from the slice (through its own snapshot),
 * never from this source tree. So a stale slice is a reference that lies.
 * `bin/export-help-slice.php` builds the slice; this suite runs it again on
 * every run and compares.
 *
 * The script is run as a process, not included: it declares an `env()` of its
 * own to ask `config/slen-sdk.php` which variable moves which key, and in this
 * process Laravel's `env()` is already there.
 *
 * "Committed equals fresh" alone would only prove that the exporter is
 * repeatable, so the rest of the file holds what the exporter says against the
 * package by another road than the exporter's own: the parameters are compared
 * with a real `SlenConfig`, the keys and their variables are read off the text of
 * the config file, the link from a key to a parameter is driven through the
 * service provider, and the symbols are compared with the files of `src/`.
 * What the exporter makes of a config file the package does not have — keys
 * cast to a boolean, three variables behind one key — is shown on a copy of
 * the package in a scratch directory (`exportWithConfigFile()`).
 *
 * The shape of the slice is described in `AGENTS.md` ("The help slice").
 * When this suite is red after a change to the SDK: regenerate
 * (`php bin/export-help-slice.php --write`, from the root), commit the slice
 * with the change, and re-run the console's `help-sync`. The slice sits
 * where `Paths::CONTRACT` says: run the suite with that directory mounted.
 */
class HelpSliceTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../bin/export-help-slice.php';

    private const COMMITTED = Paths::PACKAGE.'/help-slice.json';

    /** The call table both SDKs read: the operation of each call comes from it. */
    private const CALL_TABLE = Paths::CONTRACT.'/calls.json';

    private const KEYS = ['schema', 'language', 'package', 'client', 'options', 'config_file', 'calls', 'symbols'];

    /** @var array{code: int, out: string, err: string}|null */
    private static ?array $printed = null;

    public function testTheCommittedSliceIsWhatTheExporterPrintsNow(): void
    {
        $printed = self::printed();

        self::assertSame('', $printed['err']);
        self::assertSame(0, $printed['code']);
        self::assertFileExists(self::COMMITTED, 'Write the slice: php bin/export-help-slice.php --write');
        // The command names the entities that differ; the last line holds the bytes.
        self::assertSame('', self::export('--check')['err']);
        self::assertSame((string) file_get_contents(self::COMMITTED), $printed['out']);
    }

    public function testTheSlicePrintsTheSameOnEveryRunAndSaysNothingAboutTheMachine(): void
    {
        $printed = self::printed()['out'];

        self::assertSame($printed, self::export()['out']);
        // `assertFalse`, not `assertStringNotContainsString`: a failure should not print the whole slice.
        self::assertFalse(str_contains($printed, realpath(Paths::PACKAGE).'/'), 'The slice names a path of this machine.');
        self::assertTrue(str_ends_with($printed, "}\n"), 'The slice ends with one line break.');
        self::assertFalse(str_contains($printed, "\r"), 'The slice has LF line ends.');
    }

    public function testTheSliceKeepsItsKeysAndItsListsInTheOrderItsReadersAreTold(): void
    {
        $slice = self::slice();
        $sorted = static function (array $names): array {
            sort($names, SORT_STRING);

            return $names;
        };

        self::assertSame(self::KEYS, array_keys($slice));
        self::assertSame(2, $slice['schema']);
        self::assertSame('php', $slice['language']);
        self::assertSame(['class' => SlenSdk::class, 'options' => SlenConfig::class], $slice['client']);

        foreach (['symbols' => $slice['symbols'], 'calls' => $slice['calls'], 'dependencies' => $slice['package']['dependencies']] as $list => $entries) {
            self::assertSame($sorted(array_column($entries, 'name')), array_column($entries, 'name'), $list.' are sorted by name, byte by byte');
        }
    }

    /**
     * The HTTP operation of a call is its line in `contract/calls.json` —
     * which `ContractTest` holds to the code — and nothing
     * else: every line that names php is a call of the slice with that
     * operation, and every other call says it makes none: the two calls of the
     * webhooks, which check a webhook where it arrives.
     */
    public function testEveryCallCarriesTheOperationOfItsLineInTheCallTable(): void
    {
        $table = json_decode((string) file_get_contents(self::CALL_TABLE), true, 16, JSON_THROW_ON_ERROR)['calls'];
        $lines = array_filter($table, static function (array $line): bool {
            return in_array('php', $line['sdks'], true);
        });
        $calls = array_column(self::slice()['calls'], 'operation', 'name');

        self::assertSame([], array_values(array_diff(array_keys($lines), array_keys($calls))), 'A line of the call table that names php is not a call of the slice.');

        foreach ($calls as $name => $operation) {
            self::assertSame($lines[$name]['operation'] ?? null, $operation, $name);
        }

        self::assertSame(
            ['webhooks.constructEvent', 'webhooks.verify'],
            array_keys(array_filter($calls, static function (?string $operation): bool {
                return $operation === null;
            })),
            'Only the calls of the webhooks make no request; a call without a line has no row in ContractTest either.',
        );
        self::assertSame('operation', array_key_last(self::slice()['calls'][0]), 'The operation is the last key of a call (AGENTS.md, "A call").');
    }

    public function testCheckPassesOnTheCommittedSlice(): void
    {
        self::assertSame(['code' => 0, 'out' => '', 'err' => ''], self::export('--check'));
    }

    public function testCheckFailsOnAStaleSliceAndNamesWhatMoved(): void
    {
        $stale = self::slice();
        $dropped = array_pop($stale['options']);
        // A name no parameter can have, so the stale entry is never a real one.
        $stale['options'][] = ['name' => 'gone since'] + $dropped;
        $stale['calls'][0]['signature'] = 'something else';
        $stale['config_file']['keys'][0]['env'] = ['SOME_OTHER_VARIABLE'];
        $stale['package']['version'] = '0.0.0';

        $checked = self::withScratchFile(static function (string $file) use ($stale): array {
            file_put_contents($file, json_encode($stale, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

            return self::export('--check', '--out='.$file);
        });

        self::assertSame(1, $checked['code']);
        self::assertSame('', $checked['out']);
        self::assertStringContainsString('is not what the code says now', $checked['err']);
        self::assertStringContainsString('+ options: '.$dropped['name'], $checked['err']);
        self::assertStringContainsString('- options: gone since', $checked['err']);
        self::assertStringContainsString('~ calls: '.$stale['calls'][0]['name'], $checked['err']);
        // A key of the config file is named like any other entity, not as "the config file changed".
        self::assertStringContainsString('~ config_file: '.$stale['config_file']['keys'][0]['key']."\n", $checked['err']);
        self::assertStringNotContainsString("~ config_file\n", $checked['err']);
        self::assertStringContainsString('~ package', $checked['err']);
        self::assertStringContainsString('--write', $checked['err']);
        // Nothing about the rest: one line per entity that moved.
        self::assertStringNotContainsString('symbols', $checked['err']);
    }

    public function testCheckTellsTheSameFactsWrittenDifferentlyFromOtherFacts(): void
    {
        $compact = json_encode(self::slice(), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $checked = self::withScratchFile(static function (string $file) use ($compact): array {
            file_put_contents($file, $compact);

            return self::export('--check', '--out='.$file);
        });

        self::assertSame(1, $checked['code']);
        self::assertStringContainsString('the same facts, written differently', $checked['err']);

        $broken = self::withScratchFile(static function (string $file): array {
            file_put_contents($file, '<<<<<<< ours');

            return self::export('--check', '--out='.$file);
        });

        self::assertSame(1, $broken['code']);
        self::assertStringContainsString('the committed slice is not JSON', $broken['err']);
    }

    public function testCheckFailsOnASliceThatIsNotThere(): void
    {
        $checked = self::withScratchFile(static function (string $file): array {
            return self::export('--check', '--out='.$file);
        });

        self::assertSame(1, $checked['code']);
        self::assertStringContainsString('is not there', $checked['err']);

        // A directory under that name is no slice either.
        $directory = self::export('--check', '--out='.sys_get_temp_dir());

        self::assertSame(1, $directory['code']);
        self::assertStringContainsString('is not there', $directory['err']);
    }

    public function testWriteAnswersTwoInOneLineWhenTheFileCannotBeWritten(): void
    {
        // A directory that is not there: the write fails as it does on a read-only tree.
        $written = self::withScratchFile(static function (string $file): array {
            return self::export('--write', '--out='.$file.'.gone/help-slice.json');
        });

        // 1 would say "the slice is stale"; nothing was compared here.
        self::assertSame(['code' => 2, 'out' => '', 'err' => "export-help-slice: cannot write help-slice.json.\n"], $written);
    }

    public function testWriteWritesTheBytesCheckThenAccepts(): void
    {
        [$written, $bytes, $checked] = self::withScratchFile(static function (string $file): array {
            return [
                self::export('--write', '--out='.$file),
                (string) file_get_contents($file),
                // Both spellings of the option, as in the Node exporter.
                self::export('--check', '--out', $file),
            ];
        });

        self::assertSame(0, $written['code']);
        self::assertSame('', $written['out']);
        self::assertSame(self::printed()['out'], $bytes);
        self::assertSame(0, $checked['code']);
    }

    public function testACommandLineItDoesNotKnowIsRefused(): void
    {
        foreach ([['--fix'], ['--write', '--check'], ['--out='], ['--out']] as $arguments) {
            $refused = self::export(...$arguments);

            self::assertSame(2, $refused['code'], implode(' ', $arguments));
            self::assertSame('', $refused['out']);
            self::assertStringContainsString('usage:', $refused['err']);
        }
    }

    public function testTheOptionsAreTheParametersOfSlenConfigEveryOneInTheOrderOfTheConstructor(): void
    {
        $options = self::slice()['options'];
        $parameters = new ReflectionClass(SlenConfig::class)->getConstructor()?->getParameters() ?? [];
        // A config built with nothing but what it cannot be built without.
        $bare = new SlenConfig('sl_live_help_slice');

        self::assertNotSame([], $parameters);
        self::assertSame(
            array_map(static function ($parameter): string {
                return $parameter->getName();
            }, $parameters),
            array_column($options, 'name'),
        );

        foreach ($options as $option) {
            self::assertSame(['name', 'type', 'required', 'default', 'default_known', 'env', 'description'], array_keys($option), $option['name']);
            self::assertNotSame('', $option['type'], $option['name']);
            // `SlenConfig` reads no environment: the variables are the config file's.
            self::assertSame([], $option['env'], $option['name']);
            // Reflection reads every default, so "no default" below is a fact and not a gap of the exporter.
            self::assertTrue($option['default_known'], $option['name']);

            if ($option['required'] === true) {
                self::assertNull($option['default'], $option['name']);

                continue;
            }

            // What the slice calls the default is what a real SlenConfig holds when the parameter is left out;
            // an object (the silent logger) is named by its code alone, and that code names its class.
            $held = self::optionOf($bare, $option['name']);
            self::assertNotSame('', $option['default']['code'], $option['name']);

            if (is_object($held) === true) {
                self::assertArrayNotHasKey('value', $option['default'], $option['name']);
                self::assertSame('new \\'.$held::class.'(…)', $option['default']['code'], $option['name']);

                continue;
            }

            self::assertArrayHasKey('value', $option['default'], $option['name']);
            self::assertSame($held, $option['default']['value'], $option['name']);
        }

        self::assertSame(['apiKey'], array_column(array_filter($options, static function (array $option): bool {
            return $option['required'];
        }), 'name'));
    }

    public function testAnOptionCarriesTheTextOfItsParamTagOnOneLine(): void
    {
        $comment = (string) new ReflectionClass(SlenConfig::class)->getConstructor()?->getDocComment();
        $described = array_filter(self::slice()['options'], static function (array $option): bool {
            return $option['description'] !== null;
        });

        self::assertNotSame([], $described);

        foreach ($described as $option) {
            self::assertDoesNotMatchRegularExpression('/\n|\s{2,}|^\s|\s$|^$|\*/', $option['description'], $option['name']);
            self::assertStringContainsString('$'.$option['name'].' '.$option['description'], (string) preg_replace('/\s+(?:\*\s+)?/', ' ', $comment), $option['name']);
        }
    }

    /**
     * The config file cannot be loaded here (`env()` needs a booted
     * framework), so this reads its text: one key per line, and on that line
     * the variables in the order they are written — `env('A', env('B'))` reads
     * `A` first. The exporter asks the file instead of reading it; the two
     * have to agree.
     */
    public function testTheConfigFileKeysAreTheKeysOfThePackageConfigWithTheVariablesBehindThem(): void
    {
        $file = self::slice()['config_file'];
        $path = dirname(Paths::CONTRACT).'/'.$file['path'];

        self::assertFileExists($path);
        self::assertSame('config/slen-sdk.php', $file['path']);
        self::assertSame('slen-sdk', $file['name']);

        preg_match_all('/^\s*\'([a-z_]+)\'\s*=>(.*)$/m', (string) file_get_contents($path), $rows, PREG_SET_ORDER);

        $written = [];

        foreach ($rows as [, $key, $expression]) {
            preg_match_all('/env\(\'([A-Z0-9_]+)\'/', $expression, $variables);
            $written[$key] = $variables[1];
        }

        self::assertNotSame([], $written);
        self::assertSame($written, array_column($file['keys'], 'env', 'key'));

        foreach ($file['keys'] as $key) {
            self::assertContains($key['type'], ['string', 'int', 'float', 'bool'], $key['key']);
            self::assertArrayHasKey('value', $key['default'], $key['key']);
        }
    }

    /**
     * The package's own config file casts to `float` and `int` and reads two
     * variables per key. What the exporter would make of anything else is
     * shown on a copy of the package with another config file: keys cast to
     * a boolean both ways, a default one of the probes happens to equal, the
     * legacy name read first, three variables, one.
     *
     * A boolean is the case that needs it: the order of two variables used to
     * be read off the value the key showed, and `(bool)` shows `true` for
     * every number a variable could be told apart by — the exporter stopped
     * at the first boolean key with two variables.
     */
    public function testAKeyIsReadWhateverItCastsItsVariablesTo(): void
    {
        $exported = self::exportWithConfigFile(<<<'PHP'
            <?php

            return [
                'api_key' => env('ACME_API_KEY', env('LEGACY_API_KEY')),
                'debug' => (bool) env('ACME_DEBUG', env('LEGACY_DEBUG', false)),
                'verify' => (bool) env('ACME_VERIFY', env('LEGACY_VERIFY', true)),
                'strict' => filter_var(env('LEGACY_STRICT', env('ACME_STRICT', 'false')), FILTER_VALIDATE_BOOL),
                'retries' => (int) env('ACME_RETRIES', env('LEGACY_RETRIES', 1)),
                'region' => env('SLEN_REGION', env('ACME_REGION', env('LEGACY_REGION', 'eu'))),
                'quiet' => (bool) env('ACME_QUIET', false),
            ];
            PHP);

        self::assertSame('', $exported['err']);
        self::assertSame(0, $exported['code']);

        $keys = json_decode($exported['out'], true, 512, JSON_THROW_ON_ERROR)['config_file']['keys'];

        self::assertSame([
            'api_key' => ['ACME_API_KEY', 'LEGACY_API_KEY'],
            'debug' => ['ACME_DEBUG', 'LEGACY_DEBUG'],
            'verify' => ['ACME_VERIFY', 'LEGACY_VERIFY'],
            // Not the alphabet and not the prefix: the variable the file reads first.
            'strict' => ['LEGACY_STRICT', 'ACME_STRICT'],
            'retries' => ['ACME_RETRIES', 'LEGACY_RETRIES'],
            'region' => ['SLEN_REGION', 'ACME_REGION', 'LEGACY_REGION'],
            'quiet' => ['ACME_QUIET'],
        ], array_column($keys, 'env', 'key'));
        self::assertSame(
            ['api_key' => 'string', 'debug' => 'bool', 'verify' => 'bool', 'strict' => 'bool', 'retries' => 'int', 'region' => 'string', 'quiet' => 'bool'],
            array_column($keys, 'type', 'key'),
        );
        self::assertSame(
            ['api_key' => null, 'debug' => false, 'verify' => true, 'strict' => false, 'retries' => 1, 'region' => 'eu', 'quiet' => false],
            array_map(static function (array $default): mixed {
                return $default['value'];
            }, array_column($keys, 'default', 'key')),
        );
        self::assertSame(
            ['api_key' => 'apiKey', 'debug' => null, 'verify' => null, 'strict' => null, 'retries' => 'retries', 'region' => null, 'quiet' => null],
            array_column($keys, 'option', 'key'),
        );
    }

    public function testAKeyThatFollowsNoneOfItsVariablesStopsTheExporter(): void
    {
        // Whatever is set, the key says the same thing: there is no order to read, and none is made up.
        $exported = self::exportWithConfigFile(<<<'PHP'
            <?php

            return [
                'mode' => env('ACME_MODE', env('LEGACY_MODE')) === null ? 'off' : 'on',
            ];
            PHP);

        self::assertSame(2, $exported['code']);
        self::assertSame('', $exported['out']);
        self::assertSame("export-help-slice: The key mode follows none of its variables when all of them are set — this script reads a key that follows exactly one; teach it this one.\n", $exported['err']);
    }

    /**
     * `option` says which parameter of `SlenConfig` a key ends up in. That is
     * driven through the service provider, the way Laravel builds the config:
     * every key is given a value of its own, and the parameter has to show the
     * value of its key.
     */
    public function testAKeyOfTheConfigFileFeedsTheOptionTheSliceSaysItFeeds(): void
    {
        $slice = self::slice();
        $settings = [];

        foreach ($slice['config_file']['keys'] as $index => $key) {
            $settings[$key['key']] = (string) (11 + $index);
        }

        $config = self::configFromLaravel($settings);
        $fed = [];

        foreach ($slice['config_file']['keys'] as $key) {
            if ($key['option'] === null) {
                // A key that feeds no parameter moves none: the config is the same with another value in it.
                self::assertEquals($config, self::configFromLaravel([$key['key'] => 'another'] + $settings), $key['key']);

                continue;
            }

            self::assertContains($key['option'], array_column($slice['options'], 'name'), $key['key']);
            self::assertEquals($settings[$key['key']], self::optionOf($config, $key['option']), $key['key'].' → '.$key['option']);
            $fed[] = $key['option'];
        }

        self::assertNotSame([], $fed);
        self::assertSame($fed, array_values(array_unique($fed)), 'one key for a parameter');
    }

    public function testEveryClassOfThePackageIsASymbolAndNothingElseIs(): void
    {
        $files = [];
        $source = (string) realpath(__DIR__.'/../src');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1);

            // `compat.php` declares no class: it is loaded for its `class_alias()` calls.
            if ($file->getExtension() === 'php' && $relative !== 'compat.php') {
                $files['Slen\\Sdk\\'.str_replace('/', '\\', substr($relative, 0, -4))] = 'src/'.$relative;
            }
        }

        ksort($files, SORT_STRING);

        $declared = array_filter(self::slice()['symbols'], static function (array $symbol): bool {
            return $symbol['alias_of'] === null;
        });

        self::assertNotSame([], $files);
        self::assertSame($files, array_column($declared, 'source', 'name'));

        foreach ($declared as $symbol) {
            $class = new ReflectionClass($symbol['name']);

            self::assertSame(match (true) {
                $class->isEnum() => 'enum',
                $class->isInterface() => 'interface',
                $class->isTrait() => 'trait',
                default => 'class',
            }, $symbol['kind'], $symbol['name']);
            self::assertSame($class->isFinal(), in_array('final', $symbol['modifiers'], true), $symbol['name']);

            foreach ([...$symbol['extends'], ...$symbol['implements']] as $parent) {
                self::assertTrue(is_subclass_of($symbol['name'], $parent), $symbol['name'].' is a '.$parent);
            }
        }
    }

    public function testAnAliasIsAnotherNameOfTheClassItNames(): void
    {
        $symbols = array_column(self::slice()['symbols'], null, 'name');
        $aliases = array_filter($symbols, static function (array $symbol): bool {
            return $symbol['alias_of'] !== null;
        });

        // No earlier name is bridged, so the slice names no alias. Should one come back, this pin goes
        // and the rules below hold it.
        self::assertSame([], $aliases);

        foreach ($aliases as $name => $alias) {
            self::assertTrue(class_exists($name), $name);
            self::assertSame($alias['alias_of'], new ReflectionClass($name)->getName(), $name);
            self::assertNull($symbols[$alias['alias_of']]['alias_of'], $name);
            self::assertSame($symbols[$alias['alias_of']]['kind'], $alias['kind'], $name);
            self::assertSame([], $alias['members'], $name);
            // The spelling is the one the source writes, not the lower case PHP keeps.
            self::assertStringContainsString(str_replace('\\', '\\\\', $name), (string) file_get_contents(__DIR__.'/../src/compat.php'), $name);
            // An alias is an earlier name kept for old consumers, and the slice says so the way the
            // TypeScript one does: a reader that leaves out what is deprecated must not take an old name
            // for a name of today.
            self::assertTrue($alias['deprecated'], $name);
        }
    }

    public function testASymbolCarriesThePublicMembersItsClassDeclaresItself(): void
    {
        foreach (self::slice()['symbols'] as $symbol) {
            if ($symbol['alias_of'] !== null) {
                continue;
            }

            $class = new ReflectionClass($symbol['name']);
            $own = static function (array $members) use ($class): array {
                return array_values(array_map(
                    static function ($member): string {
                        return $member->getName();
                    },
                    array_filter($members, static function ($member) use ($class): bool {
                        return $member->getDeclaringClass()->getName() === $class->getName();
                    }),
                ));
            };

            self::assertSame(
                [
                    ...$own($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC)),
                    ...$own($class->getProperties(ReflectionProperty::IS_PUBLIC)),
                    ...$own($class->getMethods(ReflectionMethod::IS_PUBLIC)),
                ],
                array_column($symbol['members'], 'name'),
                $symbol['name'],
            );

            foreach ($symbol['members'] as $member) {
                if ($member['kind'] !== 'method' && $member['kind'] !== 'constructor') {
                    continue;
                }

                self::assertSame([], self::signatureProblems($class->getMethod($member['name']), $member['signature']), $symbol['name'].'::'.$member['name']);
                self::assertSame($class->getMethod($member['name'])->isStatic(), $member['static'], $symbol['name'].'::'.$member['name']);
            }
        }
    }

    public function testNothingPrivateIsAMember(): void
    {
        $symbols = array_column(self::slice()['symbols'], null, 'name');

        // `SlenSdk::$slenConfig` is private, and so is every helper of a facade.
        foreach ($symbols as $symbol) {
            foreach ($symbol['members'] as $member) {
                $class = new ReflectionClass($symbol['name']);
                $public = match ($member['kind']) {
                    'property' => $class->getProperty($member['name'])->isPublic(),
                    'constant', 'case' => ($class->getReflectionConstant($member['name']) ?: null)?->isPublic(),
                    default => $class->getMethod($member['name'])->isPublic(),
                };

                self::assertTrue($public, $symbol['name'].'::'.$member['name']);
            }
        }

        self::assertNotContains('slenConfig', array_column($symbols[SlenSdk::class]['members'], 'name'));
    }

    /**
     * The same classification as `ContractTest::publicCalls()`, written out
     * again on purpose: a public method of `SlenSdk` that returns a facade
     * hands out a module whose public methods are the calls, and one that
     * answers a DTO is a call itself.
     */
    public function testTheCallsAreThePublicCallsOfTheSdk(): void
    {
        $slice = self::slice();
        $symbols = array_column($slice['symbols'], null, 'name');
        $expected = [];

        foreach (new ReflectionClass(SlenSdk::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $entry) {
            $returns = $entry->getReturnType();

            if ($entry->isStatic() === true || $returns instanceof ReflectionNamedType === false || $returns->isBuiltin() === true) {
                continue;
            }

            if (str_ends_with($returns->getName(), 'DTO') === true) {
                $expected[$entry->getName()] = $entry;

                continue;
            }

            if (str_ends_with($returns->getName(), 'Facade') === false) {
                continue;
            }

            foreach (new ReflectionClass($returns->getName())->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() === false && $method->isStatic() === false) {
                    $expected[$entry->getName().'.'.$method->getName()] = $method;
                }
            }
        }

        ksort($expected, SORT_STRING);

        self::assertNotSame([], $expected);
        self::assertSame(array_keys($expected), array_column($slice['calls'], 'name'));

        foreach ($slice['calls'] as $call) {
            $method = $expected[$call['name']];

            self::assertSame($method->getDeclaringClass()->getName(), $call['symbol'], $call['name']);
            self::assertSame($method->getName(), $call['member'], $call['name']);
            self::assertSame([], self::signatureProblems($method, $call['signature']), $call['name']);
            // A call points at a member the slice has.
            self::assertContains($call['member'], array_column($symbols[$call['symbol']]['members'], 'name'), $call['name']);
        }
    }

    public function testThePackageIsWhatComposerJsonStates(): void
    {
        $package = self::slice()['package'];
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($manifest['name'], $package['name']);
        // A library's version is its tag; composer.json states none, and the slice invents none.
        self::assertSame($manifest['version'] ?? null, $package['version']);
        self::assertSame('composer.json', $package['manifest']);
        self::assertSame(['name' => 'php', 'constraint' => $manifest['require']['php']], $package['runtime']);

        $expected = [];

        foreach ($manifest['require'] as $name => $constraint) {
            if ($name !== 'php') {
                $expected[$name] = ['name' => $name, 'constraint' => $constraint, 'optional' => false];
            }
        }

        foreach (array_keys($manifest['suggest'] ?? []) as $name) {
            $expected[$name] ??= ['name' => $name, 'constraint' => $manifest['require-dev'][$name] ?? null, 'optional' => true];
        }

        ksort($expected, SORT_STRING);

        self::assertSame(array_values($expected), $package['dependencies']);
    }

    /**
     * What a printed signature gets wrong about its method, in words: the
     * name, each parameter with its type and whether it has a default, and
     * the return type.
     *
     * @return array<int, string>
     */
    private static function signatureProblems(ReflectionMethod $method, string $signature): array
    {
        $problems = [];

        if (preg_match('/^(?:abstract )?(?:static )?'.preg_quote($method->getName(), '/').'\((.*)\)(?:: (.+))?$/', $signature, $parts) !== 1) {
            return [sprintf('"%s" is not a signature of %s()', $signature, $method->getName())];
        }

        // `self` is spelled out: PHP 8.5 names the class where 8.4 prints the
        // keyword, and the slice is the same bytes on both.
        $spelled = static function (?ReflectionType $type) use ($method): string {
            return (string) preg_replace(
                '/(?<![\\\\\w])self(?![\\\\\w])/',
                addcslashes($method->getDeclaringClass()->getName(), '\\$'),
                (string) $type,
            );
        };

        if (($parts[2] ?? '') !== $spelled($method->getReturnType())) {
            $problems[] = sprintf('returns "%s", the method returns "%s"', $parts[2] ?? '', $spelled($method->getReturnType()));
        }

        if ((preg_match('/^(?:abstract )?static /', $signature) === 1) !== $method->isStatic()) {
            $problems[] = 'static is said of a method that is not, or not said of one that is';
        }

        foreach ($method->getParameters() as $parameter) {
            $written = ($parameter->hasType() === true ? $spelled($parameter->getType()).' ' : '')
                .($parameter->isPassedByReference() === true ? '&' : '')
                .($parameter->isVariadic() === true ? '...' : '')
                .'$'.$parameter->getName();

            // A parameter starts the list or follows a comma, and ends at its default, the next comma or the end.
            if (preg_match('/(?:^|, )'.preg_quote($written, '/').'( = |, |$)/', $parts[1], $found) !== 1) {
                $problems[] = sprintf('"%s" is missing', $written);

                continue;
            }

            if (($found[1] === ' = ') !== $parameter->isDefaultValueAvailable()) {
                $problems[] = sprintf('$%s has a default on one side only', $parameter->getName());
            }
        }

        if (substr_count($parts[1], '$') !== $method->getNumberOfParameters()) {
            $problems[] = sprintf('%d parameters are printed, the method takes %d', substr_count($parts[1], '$'), $method->getNumberOfParameters());
        }

        return $problems;
    }

    public function testASignatureThatIsWrongIsSaidToBeWrong(): void
    {
        $method = new ReflectionMethod(SlenConfig::class, 'withLogger');
        $logger = 'Psr\Log\LoggerInterface';
        $config = 'Slen\Sdk\SlenConfig';

        // The method is written `withLogger(LoggerInterface $logger): self`.
        self::assertSame([], self::signatureProblems($method, "withLogger({$logger} \$logger): {$config}"));
        self::assertCount(1, self::signatureProblems($method, "withLogger({$logger} \$logger): self"));
        self::assertCount(1, self::signatureProblems($method, "withLogger({$logger} \$logger): static"));
        self::assertCount(1, self::signatureProblems($method, "withLogger({$logger} \$logger = null): {$config}"));
        self::assertCount(1, self::signatureProblems($method, "static withLogger({$logger} \$logger): {$config}"));
        self::assertCount(1, self::signatureProblems($method, "withLogger(?{$logger} \$logger): {$config}"));
        self::assertCount(1, self::signatureProblems($method, "withLogger({$logger} \$journal): {$config}"));
        self::assertCount(1, self::signatureProblems($method, "withLogger({$logger} \$logger, int \$more): {$config}"));
        self::assertCount(1, self::signatureProblems($method, "withJournal({$logger} \$logger): {$config}"));
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private static function export(string ...$arguments): array
    {
        return self::process(self::SCRIPT, ...$arguments);
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private static function process(string $script, string ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, $script, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        self::assertIsResource($process, 'The exporter is run as a process: proc_open() has to be allowed.');

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /**
     * What the exporter prints for this package with another config file.
     *
     * The exporter reads the config file that sits beside it and takes no
     * other — a switch for that would be a way to write a slice of something
     * the package is not. So the package is copied to a scratch directory
     * (the script, `composer.json`, `src/`), given the config file, and the
     * copy of the script is run there. Its `vendor/autoload.php` hands over
     * to the real one: the classes are the package's own, loaded from where
     * they are.
     *
     * @return array{code: int, out: string, err: string}
     */
    private static function exportWithConfigFile(string $config): array
    {
        $root = sys_get_temp_dir().'/help-slice-'.bin2hex(random_bytes(8));
        $package = $root.'/php';
        $source = (string) realpath(__DIR__.'/../src');

        try {
            foreach (['/bin', '/config', '/vendor', '/src', '/tests'] as $directory) {
                mkdir($package.$directory, 0o777, true);
            }

            // The call table is read where the copy's `Paths` says: its own contract/.
            mkdir($package.'/contract');
            copy(self::CALL_TABLE, $package.'/contract/calls.json');
            copy(self::SCRIPT, $package.'/bin/export-help-slice.php');
            copy(__DIR__.'/../composer.json', $package.'/composer.json');
            file_put_contents($package.'/config/slen-sdk.php', $config);
            // The copy finds its directories through a `Paths` of its own, declared before the autoloader,
            // which would bring the package's.
            copy(__DIR__.'/Paths.php', $package.'/tests/Paths.php');
            file_put_contents(
                $package.'/vendor/autoload.php',
                '<?php require __DIR__.\'/../tests/Paths.php\'; return require '.var_export((string) realpath(__DIR__.'/../vendor/autoload.php'), true).';',
            );

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                $copy = $package.'/src/'.substr($file->getPathname(), strlen($source) + 1);

                $file->isDir() === true ? mkdir($copy) : copy($file->getPathname(), $copy);
            }

            return self::process($package.'/bin/export-help-slice.php');
        } finally {
            if (is_dir($root) === true) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                    $file->isDir() === true ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }

                rmdir($root);
            }
        }
    }

    /**
     * What the exporter prints when it is given nothing. Run once for the class.
     *
     * @return array{code: int, out: string, err: string}
     */
    private static function printed(): array
    {
        return self::$printed ??= self::export();
    }

    /**
     * @return array<string, mixed>
     */
    private static function slice(): array
    {
        $printed = self::printed();

        self::assertSame(0, $printed['code'], $printed['err']);

        return json_decode($printed['out'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Runs `$body` with the path of a scratch file that is gone afterwards.
     *
     * @template T
     *
     * @param callable(string): T $body
     *
     * @return T
     */
    private static function withScratchFile(callable $body): mixed
    {
        $file = sys_get_temp_dir().'/help-slice-'.bin2hex(random_bytes(8)).'.json';

        try {
            return $body($file);
        } finally {
            if (is_file($file) === true) {
                unlink($file);
            }
        }
    }

    /**
     * What a SlenConfig holds under a parameter: the public property, or the getter of a secret.
     */
    private static function optionOf(SlenConfig $config, string $name): mixed
    {
        return property_exists($config, $name) === true && new ReflectionProperty($config, $name)->isPublic() === true
            ? $config->{$name}
            : $config->{$name}();
    }

    /**
     * The `SlenConfig` the service provider builds from the given settings — as
     * `ConfigFileTest` builds it: a bare container that says its configuration
     * is cached, so the provider does not load `config/slen-sdk.php`.
     *
     * @param array<string, string> $settings
     */
    private static function configFromLaravel(array $settings): SlenConfig
    {
        $app = new class () extends Container implements CachesConfiguration {
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

        $app->instance('config', new class ($settings) implements Repository {
            /**
             * @param array<string, string> $settings
             */
            public function __construct(private array $settings)
            {
            }

            public function has($key): bool
            {
                return $this->get($key) !== null;
            }

            public function get($key, $default = null): mixed
            {
                return $this->settings[substr((string) $key, strlen('slen-sdk.'))] ?? $default;
            }

            /**
             * @return array<string, string>
             */
            public function all(): array
            {
                return $this->settings;
            }

            public function set($key, $value = null): void
            {
            }

            public function prepend($key, $value): void
            {
            }

            public function push($key, $value): void
            {
            }
        });

        // A log manager whose every channel is silent: `log_channel` names one, and it moves no parameter.
        $app->instance('log', new class () {
            public function channel(string $name): LoggerInterface
            {
                return new NullLogger();
            }
        });

        new SlenServiceProvider($app)->register();

        return $app->make(SlenConfig::class);
    }
}
