<?php

declare(strict_types=1);

namespace Dpt\McpPhpstanWarm\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Spawns bin/mcp-phpstan-warm as a subprocess, feeds JSON-RPC on stdin, asserts responses.
 * Covers the real boot path: TCP server, phpstan worker spawn, handshake, analyse protocol.
 */
final class ServerStdioTest extends TestCase
{
    private static string $bin;
    private static string $fixtureDir;
    private static string $fixtureFile;

    /** @var list<string> temp project dirs created per test, removed in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tmpDirs = [];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public static function setUpBeforeClass(): void
    {
        self::$bin = dirname(__DIR__, 2) . '/bin/mcp-phpstan-warm';
        self::$fixtureDir  = realpath(dirname(__DIR__) . '/Fixtures/project') ?: '';
        self::$fixtureFile = self::$fixtureDir . '/src/Sample.php';

        if (!is_file(self::$bin)) {
            self::markTestSkipped('bin/mcp-phpstan-warm missing');
        }
        if (!is_file(self::$fixtureFile)) {
            self::markTestSkipped('fixture missing');
        }
    }

    public function testInitializeAndListTools(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ];
        $responses = $this->invoke($messages, withProject: false, requirePaths: false);

        self::assertSame(1, $responses[0]['id']);
        self::assertArrayHasKey('result', $responses[0]);
        self::assertSame('mcp-phpstan-warm', $responses[0]['result']['serverInfo']['name']);

        $second = array_values(array_filter($responses, fn ($r) => ($r['id'] ?? null) === 2))[0] ?? null;
        self::assertNotNull($second);
        $names = array_column($second['result']['tools'], 'name');
        self::assertContains('phpstan_analyse', $names);
    }

    public function testAnalyseDetectsTypeError(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name'      => 'phpstan_analyse',
                'arguments' => ['path' => self::$fixtureFile],
            ]],
        ];
        $responses = $this->invoke($messages, withProject: true);

        $call = array_values(array_filter($responses, fn ($r) => ($r['id'] ?? null) === 2))[0] ?? null;
        self::assertNotNull($call, 'no response for id=2');
        self::assertArrayHasKey('result', $call, 'expected result, got: ' . json_encode($call));

        $structured = $call['result']['structuredContent'] ?? null;
        self::assertIsArray($structured);
        self::assertArrayHasKey('exit_code', $structured);
        self::assertArrayHasKey('errors', $structured);
        self::assertArrayHasKey('warm_boot', $structured);
        self::assertSame(1, $structured['exit_code'], 'fixture has a type error — expected exit_code 1');
        self::assertFalse($structured['warm_boot'], 'first call should be cold boot');
        self::assertNotEmpty($structured['errors']);
        self::assertArrayHasKey('message', $structured['errors'][0]);
    }

    public function testAnalyseFiltersIgnoredErrors(): void
    {
        $ignoredNeon = self::$fixtureDir . '/phpstan-with-ignores.neon';
        if (!is_file($ignoredNeon)) {
            self::markTestSkipped('phpstan-with-ignores.neon fixture missing');
        }

        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name'      => 'phpstan_analyse',
                'arguments' => ['path' => self::$fixtureFile],
            ]],
        ];

        $args = [
            '--working-dir=' . self::$fixtureDir,
            '--config=' . $ignoredNeon,
            '--paths=' . self::$fixtureDir . '/src',
        ];
        $responses = $this->invokeWithArgs($messages, $args);

        $call = array_values(array_filter($responses, fn ($r) => ($r['id'] ?? null) === 2))[0] ?? null;
        self::assertNotNull($call, 'no response for id=2');
        self::assertArrayHasKey('result', $call, 'expected result, got: ' . json_encode($call));

        $structured = $call['result']['structuredContent'] ?? null;
        self::assertIsArray($structured);
        self::assertSame(
            0,
            $structured['exit_code'],
            'return.type is in ignoreErrors — error should be filtered out. errors=' . json_encode($structured['errors'] ?? [])
        );
        self::assertEmpty(
            $structured['errors'],
            'return.type error should be suppressed by ignoreErrors'
        );
    }

    public function testWarmBootOnSecondCall(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name'      => 'phpstan_analyse',
                'arguments' => ['path' => self::$fixtureFile],
            ]],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name'      => 'phpstan_analyse',
                'arguments' => ['path' => self::$fixtureFile],
            ]],
        ];
        $responses = $this->invoke($messages, withProject: true);

        $third = array_values(array_filter($responses, fn ($r) => ($r['id'] ?? null) === 3))[0] ?? null;
        self::assertNotNull($third, 'no response for id=3');
        $structured = $third['result']['structuredContent'];
        self::assertTrue($structured['warm_boot'], 'second tools/call should reuse warm worker');
    }

    /**
     * Staleness guard: an edit made BETWEEN two analyse calls on the same warm
     * worker must be reflected on the second call.
     *
     * Unlike phpunit (which *executes* classes and so can't reload them — see
     * mcp-phpunit-warm#... / claude-supertool#265), phpstan parses source to an
     * AST and rebuilds reflection from it, so re-analysing a re-read file should
     * surface the edit. This test pins that: clean file → 0 errors, introduce a
     * type error on disk → the same warm worker reports it.
     */
    public function testEditedSourceIsReanalysedAcrossCalls(): void
    {
        $project = $this->makeProject(withError: false);
        $file    = $project . '/src/StanProbe.php';

        $proc = $this->spawnServer($project);

        try {
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]]);
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            // Clean file → no errors.
            $this->send($proc['stdin'], $this->analyseCall(2, $file));
            $first = $this->readResponse($proc['stdout'], 2);
            self::assertSame(
                0,
                $first['result']['structuredContent']['exit_code'],
                'clean fixture should analyse with no errors, got: ' . json_encode($first['result']['structuredContent'] ?? []) . $this->stderrTail($proc['stderr'])
            );

            // Introduce a type error on disk; bump mtime past 1s granularity.
            file_put_contents($file, $this->probeClass(withError: true));
            touch($file, time() + 5);

            // Same warm worker must re-read the file and report the new error.
            $this->send($proc['stdin'], $this->analyseCall(3, $file));
            $second = $this->readResponse($proc['stdout'], 3);
            $structured = $second['result']['structuredContent'];
            self::assertTrue($structured['warm_boot'], 'second call should reuse the warm worker' . $this->stderrTail($proc['stderr']));
            self::assertSame(
                1,
                $structured['exit_code'],
                'edited source introduces a type error — warm worker must re-analyse and report it (stale reflection would still report 0)' . $this->stderrTail($proc['stderr'])
            );
            self::assertNotEmpty($structured['errors']);
            // Specifically the introduced return-type mismatch, not just any error.
            self::assertStringContainsStringIgnoringCase(
                'string',
                $structured['errors'][0]['message'] ?? '',
                'expected the return-type error (int returned where string declared), got: ' . json_encode($structured['errors'])
            );
        } finally {
            fclose($proc['stdin']);
            stream_get_contents($proc['stdout']);
            fclose($proc['stdout']);
            proc_close($proc['handle']);
        }
    }

    /**
     * Cross-file staleness guard: editing a DEPENDENCY between analyse calls must
     * be reflected when a DEPENDENT is analysed next.
     *
     * The earlier test only edits the file being analysed — phpstan re-reads the
     * target's AST, so that path was always fine. The trap is a dependency: the
     * worker memoises a class's reflection for its whole life and re-analysing the
     * dependency alone does NOT refresh it, so a dependent re-analysed afterwards
     * was served the stale reflection and SILENTLY missed an error a cold run
     * catches. The runner now respawns the worker when a non-target analysed file
     * changed since boot. This pins it: User::go() calls Dep::value(); remove
     * Dep::value() on disk; re-analysing User must report the undefined method.
     */
    public function testStaleDependencyIsCaughtWhenDependentReanalysed(): void
    {
        $project = $this->makeDependencyProject();
        $user    = $project . '/src/User.php';
        $dep     = $project . '/src/Dep.php';

        $proc = $this->spawnServer($project);

        try {
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]]);
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            // Dependent is clean while Dep::value() exists.
            $this->send($proc['stdin'], $this->analyseCall(2, $user));
            self::assertSame(
                0,
                $this->readResponse($proc['stdout'], 2)['result']['structuredContent']['exit_code'],
                'User should be clean while Dep::value() exists' . $this->stderrTail($proc['stderr'])
            );

            // Remove the depended-on method on disk (mirrors editing the dependency),
            // then validate the edited dependency itself — Dep alone is still fine.
            file_put_contents($dep, "<?php\n\ndeclare(strict_types=1);\n\nfinal class Dep\n{\n    public function other(): int\n    {\n        return 2;\n    }\n}\n");
            touch($dep, time() + 5);
            $this->send($proc['stdin'], $this->analyseCall(3, $dep));
            $this->readResponse($proc['stdout'], 3);

            // Re-analyse the dependent. The worker reflected Dep with value() at boot;
            // without a respawn it would still report 0. It must now report the call
            // to the now-undefined Dep::value().
            $this->send($proc['stdin'], $this->analyseCall(4, $user));
            $structured = $this->readResponse($proc['stdout'], 4)['result']['structuredContent'];
            self::assertSame(
                1,
                $structured['exit_code'],
                'editing the dependency must surface on the dependent (stale reflection would report 0)' . $this->stderrTail($proc['stderr'])
            );
            self::assertNotEmpty($structured['errors']);
            self::assertStringContainsStringIgnoringCase(
                'value',
                $structured['errors'][0]['message'] ?? '',
                'expected the undefined-method error on Dep::value(), got: ' . json_encode($structured['errors'])
            );
        } finally {
            fclose($proc['stdin']);
            stream_get_contents($proc['stdout']);
            fclose($proc['stdout']);
            proc_close($proc['handle']);
        }
    }

    /**
     * @return array{handle: resource, stdin: resource, stdout: resource, stderr: string}
     */
    private function spawnServer(string $project): array
    {
        // Capture stderr to a file (not /dev/null) so a CI failure has diagnostics.
        $stderr = $project . '/server.stderr';
        $cmd = [
            self::$bin,
            '--working-dir=' . $project,
            '--config=' . $project . '/phpstan.neon',
            '--paths=' . $project . '/src',
        ];
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
        );
        self::assertIsResource($proc);

        return ['handle' => $proc, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $stderr];
    }

    private function stderrTail(string $path): string
    {
        $contents = @file_get_contents($path);

        return ($contents === false || $contents === '') ? '' : ' | server stderr: ' . substr($contents, -1500);
    }

    /**
     * @param resource            $stdin
     * @param array<string,mixed> $message
     */
    private function send($stdin, array $message): void
    {
        fwrite($stdin, json_encode($message) . "\n");
        fflush($stdin);
    }

    /**
     * Block reading newline-delimited JSON-RPC until the response with $id arrives.
     * PHPStan worker cold boot can take ~10s — allow a generous read timeout.
     *
     * @param resource $stdout
     * @return array<string,mixed>
     */
    private function readResponse($stdout, int $id): array
    {
        stream_set_timeout($stdout, 120);
        while (($line = fgets($stdout)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === $id) {
                return $decoded;
            }
        }

        self::fail("no response for id={$id}");
    }

    /**
     * @return array<string,mixed>
     */
    private function analyseCall(int $id, string $file): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name'      => 'phpstan_analyse',
            'arguments' => ['path' => $file],
        ]];
    }

    private function makeProject(bool $withError): string
    {
        $dir = sys_get_temp_dir() . '/phpstan_mcp_regr_' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0777, true);
        $this->tmpDirs[] = $dir;

        file_put_contents($dir . '/src/StanProbe.php', $this->probeClass($withError));
        file_put_contents($dir . '/phpstan.neon', "parameters:\n    level: 5\n    paths:\n        - src\n");

        return $dir;
    }

    /**
     * Two-file fixture for the cross-file staleness test: Dep declares value(),
     * User calls it. Editing Dep on disk must surface when User is re-analysed.
     */
    private function makeDependencyProject(): string
    {
        $dir = sys_get_temp_dir() . '/phpstan_mcp_dep_' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0777, true);
        $this->tmpDirs[] = $dir;

        file_put_contents(
            $dir . '/src/Dep.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Dep\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n"
        );
        file_put_contents(
            $dir . '/src/User.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class User\n{\n    public function go(): int\n    {\n        return (new Dep())->value();\n    }\n}\n"
        );
        file_put_contents($dir . '/phpstan.neon', "parameters:\n    level: 5\n    paths:\n        - src\n");

        return $dir;
    }

    private function probeClass(bool $withError): string
    {
        $body = $withError ? 'return 42;' : "return 'ok';";
        return "<?php\n\ndeclare(strict_types=1);\n\nfinal class StanProbe\n{\n    public function getLabel(): string\n    {\n        {$body}\n    }\n}\n";
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @param list<string> $args
     * @return list<array<string,mixed>>
     */
    private function invokeWithArgs(array $messages, array $args): array
    {
        $cmd = array_merge([self::$bin], $args);
        return $this->runProcess($cmd, $messages);
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private function invoke(array $messages, bool $withProject = false, bool $requirePaths = true): array
    {
        $args = [];
        if ($withProject || $requirePaths) {
            $args[] = '--working-dir=' . self::$fixtureDir;
            $args[] = '--config=' . self::$fixtureDir . '/phpstan.neon';
            $args[] = '--paths=' . self::$fixtureDir . '/src';
        } else {
            // tools/list test: still needs --paths to pass the startup guard
            $args[] = '--working-dir=' . self::$fixtureDir;
            $args[] = '--config=' . self::$fixtureDir . '/phpstan.neon';
            $args[] = '--paths=' . self::$fixtureDir . '/src';
        }

        $cmd = array_merge([self::$bin], $args);
        return $this->runProcess($cmd, $messages);
    }

    /**
     * @param list<string> $cmd
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private function runProcess(array $cmd, array $messages): array
    {
        $stdin = '';
        foreach ($messages as $m) {
            $stdin .= json_encode($m) . "\n";
        }

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        // PHPStan worker cold boot can take up to 10s — allow generous read time.
        stream_set_timeout($pipes[1], 120);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $responses = [];
        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $responses[] = $decoded;
            }
        }
        self::assertNotEmpty($responses, 'no responses parsed. stdout=' . $stdout . ' stderr=' . $stderr);
        return $responses;
    }
}
