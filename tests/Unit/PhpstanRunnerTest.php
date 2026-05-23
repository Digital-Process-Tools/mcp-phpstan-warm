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

    /**
     * Containment regression: assertPathAllowed must reject paths outside the
     * boot --paths allowlist. The allowlist is realpath-canonicalised so symlinks
     * and `..` traversal cannot bypass it.
     */
    public function testAssertPathAllowedRejectsOutsidePaths(): void
    {
        $allowed = sys_get_temp_dir() . '/mcp-phpstan-allowed-' . bin2hex(random_bytes(4));
        $outside = sys_get_temp_dir() . '/mcp-phpstan-outside-' . bin2hex(random_bytes(4));
        mkdir($allowed, 0o700, true);
        mkdir($outside, 0o700, true);
        $insideFile  = $allowed . '/inside.php';
        $outsideFile = $outside . '/leak.php';
        file_put_contents($insideFile,  "<?php\n");
        file_put_contents($outsideFile, "<?php\n");

        $runner = new PhpstanRunner();
        $rc = new \ReflectionClass($runner);
        $rc->getProperty('allowedPaths')->setValue($runner, [realpath($allowed)]);
        $assert = $rc->getMethod('assertPathAllowed');
        $assert->setAccessible(true);

        // Inside the allowlist: no throw.
        $assert->invoke($runner, $insideFile);

        // Outside the allowlist: must throw.
        $caught = null;
        try {
            $assert->invoke($runner, $outsideFile);
        } catch (\ReflectionException|\RuntimeException $e) {
            $caught = $e->getPrevious() ?? $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertStringContainsString('outside', $caught->getMessage());

        // Non-existent path also rejected.
        $caught = null;
        try {
            $assert->invoke($runner, $allowed . '/does-not-exist.php');
        } catch (\ReflectionException|\RuntimeException $e) {
            $caught = $e->getPrevious() ?? $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $caught);

        // Cleanup.
        unlink($insideFile);
        unlink($outsideFile);
        rmdir($allowed);
        rmdir($outside);
    }

    /**
     * Containment regression: an empty allowlist preserves legacy behaviour
     * (no rejection) so operators who didn't pass --paths at boot are unaffected.
     */
    public function testAssertPathAllowedAllowsAllWhenAllowlistEmpty(): void
    {
        $runner = new PhpstanRunner();
        $rc = new \ReflectionClass($runner);
        $rc->getProperty('allowedPaths')->setValue($runner, []);
        $assert = $rc->getMethod('assertPathAllowed');
        $assert->setAccessible(true);

        // Even /etc/passwd is accepted when allowlist is empty — legacy behaviour.
        $assert->invoke($runner, '/etc/passwd');
        self::assertTrue(true); // reached without throw
    }
}
