<?php

declare(strict_types=1);

// The help slice of the PHP SDK: what the console's Help & Docs section is
// allowed to say about `slen/sdk` without reading its source — the parameters
// of `SlenConfig`, the keys of the Laravel config file with the environment
// variables behind them, the public symbols, the calls a client offers, and
// the runtime the manifest asks for.
//
// The slice is `help-slice.json`, committed. The console's sync
// step (`help-sync`) copies it into its snapshot; an article
// that names a parameter or a method is then held against the snapshot. So the
// file has to be what the code says today: `tests/HelpSliceTest.php` runs this
// script on every run of the suite and fails when the committed file differs.
//
//   php bin/export-help-slice.php             print the slice
//   php bin/export-help-slice.php --write     write help-slice.json
//   php bin/export-help-slice.php --check     exit 1 and say what differs
//   … --out <file>                            another file for --write / --check
//
// Where each fact is read from:
//   - the public symbols — reflection over every class the package autoloads
//     from `src/` (PHP has no export list: what can be autoloaded is public),
//     plus the names `class_alias()` gives them in a file of `autoload.files`
//     — none today;
//   - the options — the parameters of `SlenConfig::__construct()`;
//   - the config file — `config/slen-sdk.php` is ASKED, not parsed: it is
//     loaded with an `env()` of this script's own, with nothing set and then
//     with one variable set at a time, and a key belongs to the variable that
//     moves it;
//   - the calls — the methods of the facades `SlenSdk` hands out, and its own
//     methods that answer a type of `Data`, classified as the contract test classifies
//     them; the HTTP operation of each — its line in `contract/calls.json`,
//     which the contract test holds, or none;
//   - runtime and dependencies — `composer.json`.
//
// Nothing here may depend on the machine or the moment: no dates, no absolute
// paths, every set sorted by byte. The shape is described for its readers in
// `AGENTS.md` ("The help slice") — change both together, and bump
// `Exporter::SCHEMA` when a reader written against the old shape would misread
// the new one.
//
// The directories it reads are those of `tests/Paths.php`. It is a tool of
// this repository, run on its own: it needs the dev
// dependencies (the Laravel classes `src/Laravel/` extends), and it has to
// define `env()` before Composer's autoloader loads Laravel's — which is why
// the suite runs it as a process instead of including it. It writes nothing
// unless asked to.

namespace Slen\SdkHelpSlice {
    use ReflectionClass;
    use ReflectionClassConstant;
    use ReflectionMethod;
    use ReflectionNamedType;
    use ReflectionParameter;
    use ReflectionProperty;
    use ReflectionType;
    use RuntimeException;
    use Slen\Sdk\Tests\Paths;
    use Throwable;

    /** The environment the config file is shown: nothing is set unless a probe sets it. */
    final class Environment
    {
        /** @var array<string, string> */
        public static array $set = [];

        /** @var array<int, string> the names that were asked for, in the order of the first question */
        public static array $asked = [];

        public static function read(string $name, mixed $default): mixed
        {
            if (in_array($name, self::$asked, true) === false) {
                self::$asked[] = $name;
            }

            return self::$set[$name] ?? $default;
        }
    }

    final class Exporter
    {
        public const SCHEMA = 2;

        /** The class a consumer builds, and the class it is built from. */
        private const CLIENT = 'Slen\\Sdk\\SlenSdk';

        private const OPTIONS = 'Slen\\Sdk\\SlenConfig';

        /** This SDK's name in `contract/calls.json`. */
        private const TABLE_SDK = 'php';

        /**
         * What a variable is set to when the config file is asked which key it
         * moves: one value, and another to tell it from. The pair has to stay
         * two different things whatever a key does to what it reads — as text,
         * as a number, and as a boolean, where any two numbers other than zero
         * would both read as true.
         */
        private const SET = '1';

        private const SET_OTHERWISE = '0';

        private const JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

        private const USAGE = <<<'TEXT'
            usage: php bin/export-help-slice.php [--write | --check] [--out <file>]

              (nothing)   print the slice of the tree as it is now
              --write     write it to help-slice.json
              --check     compare it with the committed file: exit 1 and say what differs
              --out       another file to write or to check

            TEXT;

        /** @var array<string, mixed> */
        private readonly array $manifest;

        /**
         * @param string $root the package: sources are named relative to it
         * @param string $package `php/` in it
         */
        public function __construct(private readonly string $root, private readonly string $package)
        {
            $this->manifest = json_decode((string) file_get_contents($package.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        }

        /**
         * The command line. Answers the exit code: 0 — done, or fresh; 1 — the
         * committed slice is stale, or not there; 2 — the command line is
         * wrong, the slice could not be built, or its file could not be
         * written or read.
         *
         * @param array<int, string> $arguments
         */
        public static function main(array $arguments): int
        {
            // A float is written the shortest way that reads back as itself, whatever php.ini says.
            ini_set('serialize_precision', '-1');

            $file = Paths::PACKAGE.'/help-slice.json';
            $mode = 'print';

            for ($index = 0; $index < count($arguments); $index++) {
                $argument = $arguments[$index];

                if (($argument === '--write' || $argument === '--check') && $mode === 'print') {
                    $mode = substr($argument, 2);

                    continue;
                }

                if (str_starts_with($argument, '--out=') === true && strlen($argument) > 6) {
                    $file = substr($argument, 6);

                    continue;
                }

                if ($argument === '--out' && isset($arguments[$index + 1]) === true) {
                    $file = $arguments[++$index];

                    continue;
                }

                if ($argument === '--help' || $argument === '-h') {
                    fwrite(STDOUT, self::USAGE);

                    return 0;
                }

                fwrite(STDERR, self::USAGE);

                return 2;
            }

            try {
                $fresh = self::render((new self(Paths::PACKAGE, Paths::PACKAGE))->slice());
            } catch (Throwable $failure) {
                fwrite(STDERR, 'export-help-slice: '.$failure->getMessage()."\n");

                return 2;
            }

            if ($mode === 'print') {
                fwrite(STDOUT, $fresh);

                return 0;
            }

            if ($mode === 'write') {
                if (@file_put_contents($file, $fresh) === false) {
                    fwrite(STDERR, sprintf("export-help-slice: cannot write %s.\n", basename($file)));

                    return 2;
                }

                fwrite(STDERR, sprintf("wrote %s\n", basename($file)));

                return 0;
            }

            if (is_file($file) === false) {
                fwrite(STDERR, sprintf("%s is not there — write it: php bin/export-help-slice.php --write\n", basename($file)));

                return 1;
            }

            $committed = @file_get_contents($file);

            if ($committed === false) {
                fwrite(STDERR, sprintf("export-help-slice: cannot read %s.\n", basename($file)));

                return 2;
            }

            $found = self::differences($committed, $fresh);

            if ($found === []) {
                return 0;
            }

            fwrite(STDERR, sprintf(
                "%s is not what the code says now:\n%s\nRegenerate it (php bin/export-help-slice.php --write), commit it with the change, and re-run the console's help-sync.\n",
                basename($file),
                implode("\n", array_map(static function (string $line): string {
                    return '  '.$line;
                }, $found)),
            ));

            return 1;
        }

        /**
         * The slice of the tree as it is now.
         *
         * @return array<string, mixed>
         */
        public function slice(): array
        {
            $classes = $this->classes();
            $options = $this->options();

            return [
                'schema' => self::SCHEMA,
                'language' => 'php',
                'package' => $this->package(),
                'client' => ['class' => self::CLIENT, 'options' => self::OPTIONS],
                'options' => $options,
                'config_file' => $this->configFile(array_column($options, 'name')),
                'calls' => $this->withOperations($this->calls($classes)),
                'symbols' => $this->symbols($classes),
            ];
        }

        /**
         * The bytes of the file: two spaces, LF, one line break at the end.
         *
         * @param array<string, mixed> $slice
         */
        public static function render(array $slice): string
        {
            // PHP indents by four. A JSON string holds no raw line break, so
            // every run of spaces at the start of a line is indentation.
            $json = preg_replace_callback(
                '/^(?: {4})+/m',
                static function (array $indent): string {
                    return str_repeat(' ', intdiv(strlen($indent[0]), 2));
                },
                json_encode($slice, self::JSON),
            );

            return $json."\n";
        }

        /**
         * What differs between a committed slice and a fresh one, by entity:
         * `+ options: proxy`, `- calls: customers.balance`,
         * `~ symbols: Slen\Sdk\SlenSdk`, `~ config_file: timeout`. Empty when
         * the two are the same bytes.
         *
         * @return array<int, string>
         */
        public static function differences(string $committed, string $fresh): array
        {
            if ($committed === $fresh) {
                return [];
            }

            $before = json_decode($committed, true);

            if (is_array($before) === false) {
                return ['the committed slice is not JSON'];
            }

            $after = json_decode($fresh, true, 512, JSON_THROW_ON_ERROR);
            $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
            $found = [];

            sort($keys, SORT_STRING);

            foreach ($keys as $key) {
                $was = $before[$key] ?? null;
                $is = $after[$key] ?? null;

                if (self::listed($was, 'name') === true && self::listed($is, 'name') === true) {
                    $found = [...$found, ...self::moved($key, $was, $is, 'name')];

                    continue;
                }

                if (self::listed($was['keys'] ?? null, 'key') === true && self::listed($is['keys'] ?? null, 'key') === true) {
                    // The config file: its keys one by one, then whatever else it says of itself.
                    $found = [...$found, ...self::moved($key, $was['keys'], $is['keys'], 'key')];
                    $was = array_diff_key($was, ['keys' => true]);
                    $is = array_diff_key($is, ['keys' => true]);
                }

                if ($was !== $is) {
                    $found[] = '~ '.$key;
                }
            }

            return $found === [] ? ['the same facts, written differently (indentation, key order, line ends)'] : $found;
        }

        /**
         * True for a list whose every entry is called something under `$by`.
         */
        private static function listed(mixed $list, string $by): bool
        {
            if (is_array($list) === false || array_is_list($list) === false) {
                return false;
            }

            foreach ($list as $item) {
                if (is_array($item) === false || is_string($item[$by] ?? null) === false) {
                    return false;
                }
            }

            return true;
        }

        /**
         * The entries of one list that were added, dropped or changed.
         *
         * @param array<int, array<string, mixed>> $was
         * @param array<int, array<string, mixed>> $is
         *
         * @return array<int, string>
         */
        private static function moved(string $list, array $was, array $is, string $by): array
        {
            $old = array_column($was, null, $by);
            $now = array_column($is, null, $by);
            $names = array_values(array_unique([...array_keys($old), ...array_keys($now)]));
            $moved = [];

            sort($names, SORT_STRING);

            foreach ($names as $name) {
                $sign = match (true) {
                    array_key_exists($name, $old) === false => '+',
                    array_key_exists($name, $now) === false => '-',
                    $old[$name] !== $now[$name] => '~',
                    default => null,
                };

                if ($sign !== null) {
                    $moved[] = sprintf('%s %s: %s', $sign, $list, $name);
                }
            }

            if ($moved === [] && $was !== $is) {
                $moved[] = sprintf('~ %s: the order', $list);
            }

            return $moved;
        }

        // -------------------------------------------------------------------
        // The package, as its manifest states it.

        /**
         * @return array<string, mixed>
         */
        private function package(): array
        {
            $required = $this->manifest['require'] ?? [];
            $dependencies = [];

            foreach ($required as $name => $constraint) {
                if ($name !== 'php') {
                    $dependencies[] = ['name' => $name, 'constraint' => $constraint, 'optional' => false];
                }
            }

            // `suggest` names what the package can use and does not require.
            // Composer holds a consumer to no version of it; the one given here
            // is the range the package's own suite installs (`require-dev`).
            foreach (array_keys($this->manifest['suggest'] ?? []) as $name) {
                if (array_key_exists($name, $required) === false) {
                    $dependencies[] = ['name' => $name, 'constraint' => $this->manifest['require-dev'][$name] ?? null, 'optional' => true];
                }
            }

            usort($dependencies, static function (array $left, array $right): int {
                return strcmp($left['name'], $right['name']);
            });

            return [
                'name' => $this->manifest['name'],
                // Composer reads a library's version off its tags; the manifest states one only when it is told to.
                'version' => $this->manifest['version'] ?? null,
                'manifest' => $this->relative($this->package.'/composer.json'),
                'runtime' => ['name' => 'php', 'constraint' => $required['php'] ?? null],
                'dependencies' => $dependencies,
            ];
        }

        // -------------------------------------------------------------------
        // The options of the client.

        /**
         * @return array<int, array<string, mixed>>
         */
        private function options(): array
        {
            $constructor = (new ReflectionClass(self::OPTIONS))->getConstructor();

            if ($constructor === null) {
                throw new RuntimeException(sprintf('%s has no constructor — this script reads its parameters.', self::OPTIONS));
            }

            $described = self::describedParameters((string) $constructor->getDocComment());
            $options = [];

            foreach ($constructor->getParameters() as $parameter) {
                $options[] = [
                    'name' => $parameter->getName(),
                    'type' => self::type($parameter->getType(), $constructor->getDeclaringClass()),
                    'required' => $parameter->isOptional() === false,
                    'default' => $parameter->isDefaultValueAvailable() === true ? self::literal($parameter) : null,
                    // A key of the TypeScript slice, where a default may be something the exporter
                    // cannot read. Reflection reads every one: `null` above is "there is none".
                    'default_known' => true,
                    // `SlenConfig` reads no environment itself: in Laravel the variables come
                    // through the config file — see `config_file.keys[].option`.
                    'env' => [],
                    'description' => $described[$parameter->getName()] ?? null,
                ];
            }

            return $options;
        }

        /**
         * `@param float $timeout seconds one attempt may take` → `timeout` → that text, on one line.
         *
         * @return array<string, string>
         */
        private static function describedParameters(string $docComment): array
        {
            $lines = preg_replace('#^\s*/?\*+/?[ \t]?#m', '', $docComment) ?? '';
            $described = [];

            foreach (preg_split('/^(?=@)/m', $lines) ?: [] as $block) {
                if (preg_match('/^@param\s+.+?\s+\$(\w+)(.*)$/s', $block, $found) === 1) {
                    $text = trim((string) preg_replace('/\s+/', ' ', $found[2]));

                    if ($text !== '') {
                        $described[$found[1]] = $text;
                    }
                }
            }

            return $described;
        }

        // -------------------------------------------------------------------
        // The Laravel config file.

        /**
         * @param array<int, string> $options the parameters of `SlenConfig`
         *
         * @return array<string, mixed>
         */
        private function configFile(array $options): array
        {
            $files = glob($this->package.'/config/*.php') ?: [];

            if (count($files) !== 1) {
                throw new RuntimeException(sprintf('config/ holds %d files — this script reads exactly one.', count($files)));
            }

            if ((new \ReflectionFunction('env'))->getFileName() !== __FILE__) {
                throw new RuntimeException('env() is not the one this script declares — run it on its own.');
            }

            $path = $files[0];
            $ask = static function (array $set) use ($path): array {
                Environment::$set = $set;
                $config = require $path;
                Environment::$set = [];

                if (is_array($config) === false) {
                    throw new RuntimeException(sprintf('%s does not return an array.', basename($path)));
                }

                return $config;
            };

            Environment::$asked = [];
            $unset = $ask([]);
            $variables = Environment::$asked;
            $feeds = [];
            $types = [];

            foreach ($unset as $key => $value) {
                if (is_array($value) === true) {
                    throw new RuntimeException(sprintf('The key %s is a nested array — this script reads flat keys; teach it nesting.', $key));
                }
            }

            foreach ($variables as $variable) {
                $moved = [];

                // Both values are tried: one of them may be what the key holds anyway
                // (`'1'` under `(bool) env(…, true)`), and then only the other moves it.
                foreach ([self::SET, self::SET_OTHERWISE] as $set) {
                    foreach ($ask([$variable => $set]) as $key => $value) {
                        if ($value !== $unset[$key]) {
                            $moved[$key] = get_debug_type($value);
                        }
                    }
                }

                if ($moved === []) {
                    throw new RuntimeException(sprintf('%s reads %s, and setting it moves no key.', basename($path), $variable));
                }

                foreach ($moved as $key => $type) {
                    $feeds[$key][] = $variable;
                    $types[$key] = $type;
                }
            }

            $keys = [];

            foreach ($unset as $key => $value) {
                $option = self::optionOf($key, $options);

                $keys[] = [
                    'key' => $key,
                    // What the key holds once a variable is set: `string` unless the file casts it.
                    'type' => $types[$key] ?? get_debug_type($value),
                    'default' => ['code' => self::code($value), 'value' => $value],
                    'env' => self::byPriority($ask, $key, $feeds[$key] ?? []),
                    'option' => $option,
                ];
            }

            return [
                'path' => $this->relative($path),
                // The name Laravel knows the file by: `config('slen-sdk.timeout')`.
                'name' => basename($path, '.php'),
                'keys' => $keys,
            ];
        }

        /**
         * The parameter a key of the config file feeds: the one named as the key in camel case
         * (`connect_timeout` → `connectTimeout`), else the only one that name begins
         * (`webhook_tolerance` → `webhookToleranceSeconds`); none when there is no such parameter.
         *
         * @param array<int, string> $options
         */
        private static function optionOf(string $key, array $options): ?string
        {
            $name = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));

            if (in_array($name, $options, true) === true) {
                return $name;
            }

            $begun = array_values(array_filter($options, static function (string $option) use ($name): bool {
                return str_starts_with($option, $name);
            }));

            return count($begun) === 1 ? $begun[0] : null;
        }

        /**
         * The variables of one key, the one that wins first. With all of them
         * set, the winner is the one the key still follows: all are set to one
         * value, then each in turn to the other, and the variable whose turn
         * moves the key wins; it is taken out, and so on.
         *
         * The value of the key is only compared with itself, never read back
         * as the variable's — so a key may cast what it reads to anything, a
         * boolean included (`(bool) env('A', env('B', false))`), where every
         * distinguishable number reads as the same `true`.
         *
         * @param callable(array<string, string>): array<string, mixed> $ask
         * @param array<int, string> $variables
         *
         * @return array<int, string>
         */
        private static function byPriority(callable $ask, string $key, array $variables): array
        {
            $ordered = [];

            while (count($variables) > 1) {
                $all = array_fill_keys($variables, self::SET_OTHERWISE);
                $held = $ask($all)[$key];
                $followed = array_values(array_filter(
                    $variables,
                    static function (string $variable) use ($ask, $all, $key, $held): bool {
                        return $ask([$variable => self::SET] + $all)[$key] !== $held;
                    },
                ));

                if (count($followed) !== 1) {
                    throw new RuntimeException(sprintf(
                        'The key %s follows %s of its variables when all of them are set — this script reads a key that follows exactly one; teach it this one.',
                        $key,
                        $followed === [] ? 'none' : 'several',
                    ));
                }

                $ordered[] = $followed[0];
                $variables = array_values(array_diff($variables, $followed));
            }

            return [...$ordered, ...$variables];
        }

        // -------------------------------------------------------------------
        // The public symbols.

        /**
         * Every class the package autoloads from its PSR-4 directories, by
         * name, with the file that declares it.
         *
         * @return array<class-string, string>
         */
        private function classes(): array
        {
            $apart = array_map(function (string $file): string|false {
                return realpath($this->package.'/'.$file);
            }, $this->manifest['autoload']['files'] ?? []);
            $classes = [];

            foreach ($this->manifest['autoload']['psr-4'] ?? [] as $prefix => $directory) {
                $base = (string) realpath($this->package.'/'.$directory);
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

                foreach ($files as $file) {
                    $path = $file->getPathname();

                    if ($file->getExtension() !== 'php' || in_array($path, $apart, true) === true) {
                        continue;
                    }

                    $class = $prefix.str_replace('/', '\\', substr($path, strlen($base) + 1, -4));

                    try {
                        $declared = class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
                    } catch (Throwable $failure) {
                        throw new RuntimeException(sprintf(
                            '%s cannot be loaded (%s) — the slice needs the dev dependencies.',
                            $class,
                            $failure->getMessage(),
                        ), 0, $failure);
                    }

                    if ($declared === false) {
                        throw new RuntimeException(sprintf('%s does not declare %s.', $this->relative($path), $class));
                    }

                    $classes[$class] = $this->relative($path);
                }
            }

            ksort($classes, SORT_STRING);

            return $classes;
        }

        /**
         * @param array<class-string, string> $classes
         *
         * @return array<int, array<string, mixed>>
         */
        private function symbols(array $classes): array
        {
            $symbols = [];

            foreach ($classes as $name => $source) {
                $class = new ReflectionClass($name);
                $comment = (string) $class->getDocComment();
                $parent = $class->getParentClass();
                $own = array_diff($class->getInterfaceNames(), $parent === false ? [] : $parent->getInterfaceNames());

                $symbols[$name] = [
                    'name' => $name,
                    'kind' => match (true) {
                        $class->isEnum() => 'enum',
                        $class->isInterface() => 'interface',
                        $class->isTrait() => 'trait',
                        default => 'class',
                    },
                    'source' => $source,
                    'deprecated' => self::tagged($comment, 'deprecated'),
                    'internal' => self::tagged($comment, 'internal'),
                    'alias_of' => null,
                    'modifiers' => array_values(array_filter([
                        $class->isAbstract() === true && $class->isInterface() === false ? 'abstract' : null,
                        $class->isFinal() === true ? 'final' : null,
                        $class->isReadOnly() === true ? 'readonly' : null,
                    ])),
                    'type_parameters' => [],
                    'extends' => $class->isInterface() === true ? self::outermost($own) : ($parent === false ? [] : [$parent->getName()]),
                    'implements' => $class->isInterface() === true ? [] : self::outermost($own),
                    'signature' => null,
                    'members' => self::members($class),
                ];
            }

            foreach ($this->aliases($classes) as $alias => $target) {
                // Another name for a class: it repeats nothing, the class it names says it all.
                $symbols[$alias] = [
                    'name' => $alias,
                    'kind' => $symbols[$target]['kind'],
                    'source' => $symbols[$target]['source'],
                    // `class_alias()` has nowhere to carry `@deprecated`, so it is said here. The package
                    // declares no alias today; a package keeps one only for the
                    // consumers of an old version, which is what the TypeScript package marks
                    // `@deprecated`. A reader that leaves out what is deprecated has to leave such a
                    // name out in both languages.
                    'deprecated' => true,
                    'internal' => false,
                    'alias_of' => $target,
                    'modifiers' => [],
                    'type_parameters' => [],
                    'extends' => [],
                    'implements' => [],
                    'signature' => null,
                    'members' => [],
                ];
            }

            ksort($symbols, SORT_STRING);

            return array_values($symbols);
        }

        /**
         * The interfaces nothing else in the list already extends: for
         * `A extends B`, a class that implements `A` is told to implement `A`.
         *
         * @param array<int, string> $interfaces
         *
         * @return array<int, string>
         */
        private static function outermost(array $interfaces): array
        {
            $kept = array_values(array_filter(
                $interfaces,
                static function (string $candidate) use ($interfaces): bool {
                    return array_filter(
                        $interfaces,
                        static function (string $other) use ($candidate): bool {
                            return $other !== $candidate && is_subclass_of($other, $candidate);
                        },
                    ) === [];
                },
            ));

            sort($kept, SORT_STRING);

            return $kept;
        }

        /**
         * The names `class_alias()` gave the classes of the package.
         *
         * PHP keeps an alias in lower case only, so its spelling is recovered
         * from the string literals of the files that are loaded for every
         * consumer (`autoload.files`). The package lists none, so this
         * answers nothing today; it
         * stays for a file that brings an alias back.
         *
         * @param array<class-string, string> $classes
         *
         * @return array<string, class-string> alias → the class it names
         */
        private function aliases(array $classes): array
        {
            $spelled = [];

            foreach ($this->manifest['autoload']['files'] ?? [] as $file) {
                foreach (token_get_all((string) file_get_contents($this->package.'/'.$file)) as $token) {
                    if (is_array($token) === true && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $text = stripslashes(substr($token[1], 1, -1));
                        $spelled[strtolower($text)] = $text;
                    }
                }
            }

            $aliases = [];

            foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $declared) {
                $target = (new ReflectionClass($declared))->getName();

                if ($target === $declared || array_key_exists($target, $classes) === false) {
                    continue;
                }

                if (array_key_exists(strtolower($declared), $spelled) === false) {
                    throw new RuntimeException(sprintf('%s has an alias (%s) whose spelling no file of autoload.files holds.', $target, $declared));
                }

                $aliases[$spelled[strtolower($declared)]] = $target;
            }

            return $aliases;
        }

        /**
         * The public members a class declares itself — what it inherits is its
         * parent's: constants and cases, then properties, then methods, each
         * group in the order of the source.
         *
         * @param ReflectionClass<object> $class
         *
         * @return array<int, array<string, mixed>>
         */
        private static function members(ReflectionClass $class): array
        {
            $members = [];

            foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if ($constant->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $case = $constant->isEnumCase();
                $value = $constant->getValue();
                $shown = $case === false ? ' = '.self::code($value) : (property_exists($value, 'value') === true ? ' = '.self::code($value->value) : '');

                $members[] = self::member(
                    $constant->getName(),
                    $case === true ? 'case' : 'constant',
                    false,
                    (string) $constant->getDocComment(),
                    ($case === true ? 'case ' : 'const ').$constant->getName().$shown,
                );
            }

            foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $members[] = self::member(
                    $property->getName(),
                    'property',
                    $property->isStatic(),
                    (string) $property->getDocComment(),
                    implode(' ', array_filter([
                        $property->isStatic() === true ? 'static' : null,
                        $property->isReadOnly() === true ? 'readonly' : null,
                        $property->hasType() === true ? self::type($property->getType(), $class) : null,
                        '$'.$property->getName(),
                    ])),
                );
            }

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $members[] = self::member(
                    $method->getName(),
                    $method->isConstructor() === true ? 'constructor' : 'method',
                    $method->isStatic(),
                    (string) $method->getDocComment(),
                    self::signature($method),
                );
            }

            return $members;
        }

        /**
         * @return array<string, mixed>
         */
        private static function member(string $name, string $kind, bool $static, string $comment, string $signature): array
        {
            return [
                'name' => $name,
                'kind' => $kind,
                'static' => $static,
                // A key of the TypeScript slice (`name?: type`); nothing in PHP is optional this way.
                'optional' => false,
                'deprecated' => self::tagged($comment, 'deprecated'),
                'internal' => self::tagged($comment, 'internal'),
                'signature' => $signature,
            ];
        }

        /** `get(string $externalUserId): Slen\Sdk\Modules\Customers\Data\Customer` */
        private static function signature(ReflectionMethod $method): string
        {
            $scope = $method->getDeclaringClass();
            $parameters = array_map(static function (ReflectionParameter $parameter) use ($scope): string {
                return ($parameter->hasType() === true ? self::type($parameter->getType(), $scope).' ' : '')
                    .($parameter->isPassedByReference() === true ? '&' : '')
                    .($parameter->isVariadic() === true ? '...' : '')
                    .'$'.$parameter->getName()
                    .($parameter->isDefaultValueAvailable() === true ? ' = '.self::literal($parameter)['code'] : '');
            }, $method->getParameters());

            return ($method->isAbstract() === true && $scope->isInterface() === false ? 'abstract ' : '')
                .($method->isStatic() === true ? 'static ' : '')
                .$method->getName().'('.implode(', ', $parameters).')'
                .($method->hasReturnType() === true ? ': '.self::type($method->getReturnType(), $scope) : '');
        }

        /**
         * A type as reflection prints it, with `self` and `parent` spelled
         * out. PHP 8.5 names the class where 8.4 prints the keyword, and the
         * slice has to be the same bytes on every runtime the suite runs on.
         *
         * @param ReflectionClass<object> $scope the class that declares the member
         */
        private static function type(?ReflectionType $type, ReflectionClass $scope): string
        {
            $parent = $scope->getParentClass();
            $spelled = ['self' => $scope->getName()] + ($parent === false ? [] : ['parent' => $parent->getName()]);

            return (string) preg_replace_callback(
                '/(?<![\\\\\w])(self|parent)(?![\\\\\w])/',
                static function (array $found) use ($spelled): string {
                    return $spelled[$found[1]] ?? $found[1];
                },
                (string) $type,
            );
        }

        private static function tagged(string $docComment, string $tag): bool
        {
            return preg_match('/@'.$tag.'\b/', $docComment) === 1;
        }

        // -------------------------------------------------------------------
        // The calls a client offers.

        /**
         * `operations.execute`, `health` — every public method that can be
         * reached from a client, named as the TypeScript slice names it. The
         * classification is the contract test's (`publicCalls()` in
         * `tests/Unit/ContractTest.php`): a public method of `SlenSdk` that
         * returns a facade hands out a module, and each public method of the
         * facade is a call; a method of `SlenSdk` that answers a type of `Data` is a call
         * itself; the rest (`config()`, and `withLogger()`, which answers a
         * client) are not calls.
         *
         * @param array<class-string, string> $classes
         *
         * @return array<int, array<string, mixed>>
         */
        private function calls(array $classes): array
        {
            $calls = [];

            foreach ((new ReflectionClass(self::CLIENT))->getMethods(ReflectionMethod::IS_PUBLIC) as $entry) {
                $returns = $entry->getReturnType();

                if ($entry->isStatic() === true || $returns instanceof ReflectionNamedType === false || array_key_exists($returns->getName(), $classes) === false) {
                    continue;
                }

                if (str_contains($returns->getName(), '\\Data\\') === true) {
                    $calls[] = self::call($entry->getName(), $entry);

                    continue;
                }

                if (str_ends_with($returns->getName(), 'Facade') === false) {
                    continue;
                }

                foreach ((new ReflectionClass($returns->getName()))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->isConstructor() === false && $method->isStatic() === false) {
                        $calls[] = self::call($entry->getName().'.'.$method->getName(), $method);
                    }
                }
            }

            usort($calls, static function (array $left, array $right): int {
                return strcmp($left['name'], $right['name']);
            });

            return $calls;
        }

        /**
         * Every call with the HTTP operation it makes — its line in
         * `contract/calls.json`, the table both SDKs read:
         * `POST /v1/operations/execute`, or `null` for a call that makes none.
         * The table is not built here: the contract test holds it to the code
         * (a line for every call, each call driven to the method and the path
         * of its line). What the exporter refuses is a line for a call this
         * client does not offer — the slice would then say less than the table.
         *
         * @param array<int, array<string, mixed>> $calls
         *
         * @return array<int, array<string, mixed>>
         */
        private function withOperations(array $calls): array
        {
            $file = Paths::CONTRACT.'/calls.json';
            $text = is_file($file) === true ? file_get_contents($file) : false;

            if ($text === false) {
                throw new RuntimeException('contract/calls.json could not be read.');
            }

            $table = json_decode($text, true, 16, JSON_THROW_ON_ERROR)['calls'] ?? null;

            if (is_array($table) === false) {
                throw new RuntimeException('contract/calls.json has no `calls`.');
            }

            $lines = [];

            foreach ($table as $name => $line) {
                if (is_array($line) === true && is_array($line['sdks'] ?? null) === true && in_array(self::TABLE_SDK, $line['sdks'], true) === true) {
                    $lines[(string) $name] = $line;
                }
            }

            $stray = array_values(array_diff(array_keys($lines), array_column($calls, 'name')));

            if ($stray !== []) {
                sort($stray, SORT_STRING);

                throw new RuntimeException(sprintf('contract/calls.json names calls of %s the client does not offer: %s.', self::TABLE_SDK, implode(', ', $stray)));
            }

            return array_map(static function (array $call) use ($lines): array {
                $operation = $lines[$call['name']]['operation'] ?? null;

                return $call + ['operation' => is_string($operation) === true ? $operation : null];
            }, $calls);
        }

        /**
         * @return array<string, mixed>
         */
        private static function call(string $name, ReflectionMethod $method): array
        {
            $comment = (string) $method->getDocComment();

            return [
                'name' => $name,
                'symbol' => $method->getDeclaringClass()->getName(),
                'member' => $method->getName(),
                'deprecated' => self::tagged($comment, 'deprecated'),
                'internal' => self::tagged($comment, 'internal'),
                'signature' => self::signature($method),
            ];
        }

        // -------------------------------------------------------------------
        // Values, as PHP writes them and as JSON carries them.

        /**
         * The default of a parameter: `code` is how it is written in PHP;
         * `value` is there when JSON can carry it.
         *
         * @return array{code: string, value?: mixed}
         */
        private static function literal(ReflectionParameter $parameter): array
        {
            $value = $parameter->getDefaultValue();
            $code = $parameter->isDefaultValueConstant() === true ? (string) $parameter->getDefaultValueConstantName() : self::code($value);

            if (is_object($value) === true || (is_float($value) === true && is_finite($value) === false)) {
                return ['code' => $code];
            }

            return ['code' => $code, 'value' => $value];
        }

        private static function code(mixed $value): string
        {
            if ($value === null) {
                return 'null';
            }

            if (is_bool($value) === true) {
                return $value === true ? 'true' : 'false';
            }

            if (is_int($value) === true || is_float($value) === true || is_string($value) === true) {
                return var_export($value, true);
            }

            if (is_array($value) === true) {
                $list = array_is_list($value);
                $items = [];

                foreach ($value as $key => $item) {
                    $items[] = ($list === true ? '' : self::code($key).' => ').self::code($item);
                }

                return '['.implode(', ', $items).']';
            }

            if ($value instanceof \UnitEnum) {
                return '\\'.$value::class.'::'.$value->name;
            }

            return is_object($value) === true ? 'new \\'.$value::class.'(…)' : get_debug_type($value);
        }

        /** Repository-relative, with forward slashes — the same on every machine. */
        private function relative(string $path): string
        {
            $real = realpath($path);
            $root = (string) realpath($this->root);

            if ($real === false || str_starts_with($real, $root.'/') === false) {
                throw new RuntimeException(sprintf('%s is not a file of this repository.', $path));
            }

            return substr($real, strlen($root) + 1);
        }
    }
}

namespace {
    /**
     * What `config/slen-sdk.php` reads its values through. A function at the
     * top of a file is declared before any of it runs, and Laravel's helpers
     * are declared behind `function_exists()` — so this one is the `env()` the
     * config file calls, also after Composer has loaded `illuminate/support`.
     */
    function env(string $name, mixed $default = null): mixed
    {
        return \Slen\SdkHelpSlice\Environment::read($name, $default);
    }

    $autoload = __DIR__.'/../vendor/autoload.php';

    if (is_file($autoload) === false) {
        fwrite(STDERR, "export-help-slice: vendor/ is not installed — run `composer install` first.\n");

        exit(2);
    }

    require $autoload;

    exit(\Slen\SdkHelpSlice\Exporter::main(array_slice($argv, 1)));
}
