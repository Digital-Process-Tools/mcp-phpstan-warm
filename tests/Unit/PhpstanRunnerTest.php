<?php

declare(strict_types=1);

namespace Dpt\McpPhpstanWarm\Tests\Unit;

use Dpt\McpPhpstanWarm\PhpstanRunner;
use PHPUnit\Framework\TestCase;

final class PhpstanRunnerTest extends TestCase
{
    public function testIsWarmFalseBeforeBoot(): void
    {
        $runner = new PhpstanRunner();
        self::assertFalse($runner->isWarm());
    }
}
