<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Enums;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Http\Enums\HttpMethod;
use Slen\Sdk\Tests\Paths;
use SplFileInfo;

final class EndpointTest extends TestCase
{
    private const string CALL_TABLE = Paths::CONTRACT . '/calls.json';

    private const string SOURCE = __DIR__ . '/../../../../../src';

    private const string TABLE_FILE = 'Shared/Http/Enums/Endpoint.php';

    public function testTheEndpointsAreTheOperationsOfTheCallTableBothWays(): void
    {
        /** @var array{calls: array<string, array{operation: string}>} $table */
        $table = json_decode((string) file_get_contents(self::CALL_TABLE), true, 512, JSON_THROW_ON_ERROR);
        $operations = array_values(array_unique(array_column($table['calls'], 'operation')));
        $endpoints = array_column(Endpoint::cases(), 'value');
        sort($operations);
        sort($endpoints);

        self::assertSame($operations, $endpoints);
    }

    public function testAnEndpointIsItsMethodAndItsPath(): void
    {
        self::assertSame(HttpMethod::Put, Endpoint::UpdateAccount->method());
        self::assertSame('/v1/customers/{externalUserId}/accounts/{creditType}', Endpoint::UpdateAccount->path());
        self::assertSame(HttpMethod::Get, Endpoint::Health->method());
        self::assertSame('/health', Endpoint::Health->path());
    }

    public function testNoPathOfTheApiIsWrittenOutsideTheTable(): void
    {
        $root = (string) realpath(self::SOURCE);
        $found = [];
        $read = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo === false || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            ++$read;

            if ($relative === self::TABLE_FILE) {
                continue;
            }

            foreach (self::pathsIn((string) file_get_contents($file->getPathname())) as $line) {
                $found[] = $relative . ':' . $line;
            }
        }

        self::assertGreaterThan(30, $read, 'the test has to be reading the source');
        self::assertSame([], $found, 'A path of the API is a case of Endpoint, written there and nowhere else.');
    }

    public function testTheScannerFindsAPathInAStringAndNotInAComment(): void
    {
        $code = "<?php\n// GET /v1/customers\n\$a = '/v1/customers';\n\$b = \"/health\";\n\$c = 'v1/x';\n\$d = 'PUT /v1/customers/{id}';\n";

        self::assertSame([3, 4, 6], self::pathsIn($code));
    }

    /**
     * @return list<int> the lines of the string literals that are a path of the API
     */
    private static function pathsIn(string $code): array
    {
        $lines = [];

        foreach (PhpToken::tokenize($code) as $phpToken) {
            if ($phpToken->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) === false) {
                continue;
            }

            // An operation is a path too: the method in front of it does not hide it.
            $text = (string) preg_replace('~^(GET|POST|PUT|PATCH|DELETE) ~', '', trim($phpToken->text, '\'"'));

            if (str_starts_with($text, '/v1/') || $text === '/health') {
                $lines[] = $phpToken->line;
            }
        }

        return $lines;
    }
}
