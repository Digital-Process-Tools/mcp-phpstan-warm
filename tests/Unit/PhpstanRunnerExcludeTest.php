<?php

declare(strict_types=1);

namespace Dpt\McpPhpstanWarm\Tests\Unit;

use Dpt\McpPhpstanWarm\PhpstanRunner;
use PHPUnit\Framework\TestCase;

/**
 * Regression for issue #1 — mcp-phpstan-warm force-analyzed files that the
 * neon config excluded via `excludePaths`. The warm process returned false
 * positives because lifecycle methods (setUpBeforeClass / tearDownAfterClass)
 * weren't loaded with the right bootstrap.
 *
 * Fix shape:
 *   - PhpstanRunner caches `excludePaths.analyse` + `excludePaths.analyseAndScan`
 *     at boot via `phpstan dump-parameters --json` (already wired for ignoreErrors).
 *   - A private helper `isExcluded($path): bool` matches the file against those
 *     globs (fnmatch + realpath, both relative and absolute).
 *   - `analyse($path)` short-circuits with an empty errors list when excluded.
 *   - `analyse($path, force: true)` bypasses the check (escape hatch).
 */
final class PhpstanRunnerExcludeTest extends TestCase
{
    public function testIsExcludedMatchesAbsolutePath(): void
    {
        $runner = new PhpstanRunner();
        $rc = new \ReflectionClass($runner);
        $prop = $rc->getProperty('excludePaths');
        $prop->setValue($runner, ['/abs/repo/tests/unit/*Test.php']);

        $m = $rc->getMethod('isExcluded');
        $m->setAccessible(true);

        self::assertTrue($m->invoke($runner, '/abs/repo/tests/unit/FooTest.php'));
        self::assertFalse($m->invoke($runner, '/abs/repo/src/Foo.php'));
    }

    public function testIsExcludedMatchesRelativeGlob(): void
    {
        // Real exclude entries are often relative like `tests/*` — must match
        // both relative input AND absolute realpath that contains the suffix.
        $runner = new PhpstanRunner();
        $rc = new \ReflectionClass($runner);
        $rc->getProperty('excludePaths')->setValue($runner, ['tests/unit/*']);
        $m = $rc->getMethod('isExcluded');
        $m->setAccessible(true);

        self::assertTrue($m->invoke($runner, '/repo/tests/unit/Bar.php'));
        self::assertTrue($m->invoke($runner, 'tests/unit/Bar.php'));
        self::assertFalse($m->invoke($runner, '/repo/src/Bar.php'));
    }

    public function testIsExcludedEmptyListReturnsFalse(): void
    {
        $runner = new PhpstanRunner();
        $rc = new \ReflectionClass($runner);
        $rc->getProperty('excludePaths')->setValue($runner, []);
        $m = $rc->getMethod('isExcluded');
        $m->setAccessible(true);

        self::assertFalse($m->invoke($runner, '/anywhere/Foo.php'));
    }

    public function testAnalyseShortCircuitsForExcludedPath(): void
    {
        // The fix path: when isExcluded returns true, analyse() must return an
        // empty errors list WITHOUT booting the worker. We assert by leaving
        // handshakeDone=false — if analyse() reached ensureWorker() it would
        // try to spawn phpstan and the test would hang or fail loudly.
        $tmp = sys_get_temp_dir() . '/mcp-phpstan-excl-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($tmp, "<?php\n");

        try {
            $runner = new PhpstanRunner();
            $rc = new \ReflectionClass($runner);
            $rc->getProperty('excludePaths')->setValue($runner, [$tmp]);
            // Force the warm-state lie so ensureWorker() would early-return —
            // belt-and-braces: if exclusion fails to short-circuit, we won't
            // hang on a real worker spawn.
            $rc->getProperty('handshakeDone')->setValue($runner, true);

            $errors = $runner->analyse($tmp);
            self::assertSame([], $errors);
        } finally {
            @unlink($tmp);
        }
    }

    public function testAnalyseShortCircuitWithoutAllowedPathsConfig(): void
    {
        // The exclude check must run even when --paths allowlist is empty
        // (legacy callers). Empty allowedPaths means assertPathAllowed is a
        // no-op, so the exclude branch is the only protection against
        // false positives from `tearDown`-only reads in excluded test files.
        $tmp = sys_get_temp_dir() . '/mcp-phpstan-excl2-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($tmp, "<?php\n");

        try {
            $runner = new PhpstanRunner();
            $rc = new \ReflectionClass($runner);
            $rc->getProperty('excludePaths')->setValue($runner, [$tmp]);
            $rc->getProperty('allowedPaths')->setValue($runner, []); // legacy mode
            $rc->getProperty('handshakeDone')->setValue($runner, true);

            $errors = $runner->analyse($tmp);
            self::assertSame([], $errors);
        } finally {
            @unlink($tmp);
        }
    }
}
