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
