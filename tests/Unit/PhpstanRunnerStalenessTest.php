<?php

declare(strict_types=1);

namespace Dpt\McpPhpstanWarm\Tests\Unit;

use Dpt\McpPhpstanWarm\PhpstanRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Staleness guards. A warm worker memoises reflection for its whole life, so an
 * edit that changes a class's shape must force a respawn — while an edit that
 * only touches a method body must not, or every validate call pays a cold boot.
 */
final class PhpstanRunnerStalenessTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    private function writeTemp(string $source): string
    {
        $path = sys_get_temp_dir() . '/mcp-phpstan-sig-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, $source);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function signatureOf(PhpstanRunner $runner, string $path): string
    {
        $method = (new ReflectionClass($runner))->getMethod('structuralSignature');

        return (string) $method->invoke($runner, $path);
    }

    public function testBodyOnlyEditKeepsTheSignatureStable(): void
    {
        $runner = new PhpstanRunner();

        $before = $this->writeTemp(<<<'PHP'
            <?php
            final class Probe
            {
                public function value(): int
                {
                    return 1;
                }
            }
            PHP);

        $after = $this->writeTemp(<<<'PHP'
            <?php
            final class Probe
            {
                public function value(): int
                {
                    $intermediate = 40 + 2;

                    return $intermediate;
                }
            }
            PHP);

        self::assertSame(
            $this->signatureOf($runner, $before),
            $this->signatureOf($runner, $after),
            'A body-only edit must not respawn the worker — that is the warm path.'
        );
    }

    public function testClassLevelGenericEditChangesTheSignature(): void
    {
        $runner = new PhpstanRunner();

        $before = $this->writeTemp(<<<'PHP'
            <?php
            /**
             * @extends Base<array{alpha: string}>
             */
            final class Probe extends Base
            {
            }
            PHP);

        $after = $this->writeTemp(<<<'PHP'
            <?php
            /**
             * @extends Base<array{alpha: string, beta: string}>
             */
            final class Probe extends Base
            {
            }
            PHP);

        self::assertNotSame(
            $this->signatureOf($runner, $before),
            $this->signatureOf($runner, $after),
            'The class-level generic is the exact case that served stale results before this guard.'
        );
    }

    public function testMethodSignatureEditChangesTheSignature(): void
    {
        $runner = new PhpstanRunner();

        $before = $this->writeTemp("<?php\nfinal class Probe\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n");
        $after  = $this->writeTemp("<?php\nfinal class Probe\n{\n    public function value(): ?int\n    {\n        return 1;\n    }\n}\n");

        self::assertNotSame($this->signatureOf($runner, $before), $this->signatureOf($runner, $after));
    }

    public function testPropertyTypeEditChangesTheSignature(): void
    {
        $runner = new PhpstanRunner();

        $before = $this->writeTemp("<?php\nfinal class Probe\n{\n    private int \$count = 0;\n}\n");
        $after  = $this->writeTemp("<?php\nfinal class Probe\n{\n    private ?int \$count = 0;\n}\n");

        self::assertNotSame($this->signatureOf($runner, $before), $this->signatureOf($runner, $after));
    }

    public function testFormattingAndLineCommentsDoNotChangeTheSignature(): void
    {
        $runner = new PhpstanRunner();

        $before = $this->writeTemp("<?php\nfinal class Probe\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n");
        $after  = $this->writeTemp("<?php\n\n// a note that reflection never reads\nfinal class Probe\n{\n\n    public function value(): int\n    {\n        return 1;\n    }\n\n}\n");

        self::assertSame(
            $this->signatureOf($runner, $before),
            $this->signatureOf($runner, $after),
            'Whitespace and line comments must not cost a respawn.'
        );
    }

    public function testAbstractMethodWithoutBodyDoesNotSwallowLaterDeclarations(): void
    {
        $runner = new PhpstanRunner();

        $before = $this->writeTemp("<?php\nabstract class Probe\n{\n    abstract public function value(): int;\n\n    private int \$kept = 1;\n}\n");
        $after  = $this->writeTemp("<?php\nabstract class Probe\n{\n    abstract public function value(): int;\n\n    private string \$kept = 'x';\n}\n");

        self::assertNotSame(
            $this->signatureOf($runner, $before),
            $this->signatureOf($runner, $after),
            'A bodyless abstract method must not leave the scanner stuck skipping the rest of the class.'
        );
    }

    public function testTargetStructureChangedIsFalseForAFileNeverAnalysed(): void
    {
        $runner = new PhpstanRunner();
        $path = $this->writeTemp("<?php\nfinal class Probe\n{\n}\n");

        $method = (new ReflectionClass($runner))->getMethod('targetStructureChanged');

        self::assertFalse(
            (bool) $method->invoke($runner, $path),
            'An unseen file has no memoised reflection, so there is nothing stale to respawn for.'
        );
    }

    public function testTargetStructureChangedIsTrueAfterAStructuralEdit(): void
    {
        $runner = new PhpstanRunner();
        $path = $this->writeTemp("<?php\n/**\n * @extends Base<array{alpha: string}>\n */\nfinal class Probe extends Base\n{\n}\n");

        $reflection = new ReflectionClass($runner);
        $analysed = $reflection->getProperty('analysedFiles');
        $analysed->setAccessible(true);
        $analysed->setValue($runner, [$path => $this->signatureOf($runner, $path)]);

        $method = $reflection->getMethod('targetStructureChanged');

        self::assertFalse((bool) $method->invoke($runner, $path));

        file_put_contents($path, "<?php\n/**\n * @extends Base<array{alpha: string, beta: string}>\n */\nfinal class Probe extends Base\n{\n}\n");

        self::assertTrue(
            (bool) $method->invoke($runner, $path),
            'Editing the generic after the worker reflected the class must force a respawn.'
        );
    }

    public function testPhpstanBinaryOverrideIsHonoured(): void
    {
        $fake = $this->writeTemp("<?php\n");
        putenv('MCP_PHPSTAN_PHPSTAN_BIN=' . $fake);

        try {
            $runner = new PhpstanRunner();
            $method = (new ReflectionClass($runner))->getMethod('findPhpstanBin');

            self::assertSame(realpath($fake), $method->invoke($runner));
        } finally {
            putenv('MCP_PHPSTAN_PHPSTAN_BIN');
        }
    }

    public function testPhpstanBinaryOverrideRejectsAMissingPath(): void
    {
        putenv('MCP_PHPSTAN_PHPSTAN_BIN=' . sys_get_temp_dir() . '/definitely-not-here-' . bin2hex(random_bytes(4)));

        try {
            $runner = new PhpstanRunner();
            $method = (new ReflectionClass($runner))->getMethod('findPhpstanBin');

            $this->expectException(\RuntimeException::class);
            $method->invoke($runner);
        } finally {
            putenv('MCP_PHPSTAN_PHPSTAN_BIN');
        }
    }

    public function testUnknownClassErrorIsDetected(): void
    {
        $runner = new PhpstanRunner();
        $method = (new ReflectionClass($runner))->getMethod('hasUnknownClassError');

        $unknown = [['file' => 'a.php', 'line' => 3, 'message' => 'extends unknown class Base.', 'identifier' => 'class.notFound']];
        $ordinary = [['file' => 'a.php', 'line' => 3, 'message' => 'should return string but returns int.', 'identifier' => 'return.type']];

        self::assertTrue((bool) $method->invoke($runner, $unknown));
        self::assertFalse((bool) $method->invoke($runner, $ordinary));
        self::assertFalse((bool) $method->invoke($runner, []));
    }
}
