<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use Slen\Sdk\Data\PageDTO;

final class PageDTOTest extends TestCase
{
    public function testHasMoreWhileThereIsACursor(): void
    {
        self::assertTrue((new PageDTO(['a'], 'c1'))->hasMore());
        self::assertFalse((new PageDTO([], null))->hasMore());
    }
}
