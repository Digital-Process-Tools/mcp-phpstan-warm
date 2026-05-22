<?php

declare(strict_types=1);

namespace Dpt\McpPhpstanWarm;

/**
 * Manages a persistent PHPStan worker process connected via TCP.
 *
 * PHPStan's built-in `worker` subcommand speaks NDJSON over TCP:
 *   - Worker connects TO us (we are the server, it is the client).
 *   - Handshake: worker sends {"action":"hello","identifier":"<hex>"}.
 *   - Per call: we send {"action":"analyse","files":["abs/path.php"]},
 *               worker replies {"action":"result","result":{errors,...}}.
 *   - Worker stays alive between batches; exits only when TCP closes.
 *
 * The set of analysable files is fixed at worker boot via the paths passed
 * to `phpstan worker <paths...>`. Files outside that set may fail or return
 * dependency errors — document this constraint in the README.
 */
final class PhpstanRunner
{
    private const HANDSHAKE_TIMEOUT_S = 60;
    private const ANALYSE_TIMEOUT_S   = 120;

    /** @var resource|null TCP server socket (stream_socket_server) */
    private $serverSocket = null;

    /** @var resource|null Connected worker stream */
    private $workerStream = null;

    /** @var resource|null proc_open process resource */
    private $workerProcess = null;

    private bool $handshakeDone = false;

    private int $serverPort = 0;

    public function isWarm(): bool
    {
        return $this->handshakeDone && $this->isWorkerAlive();
    }

    /**
     * Analyse a single file. Boots the worker on first call; respawns if dead.
     *
     * @return array{file: string, line: int, message: string, identifier: string|null}[]
     * @throws \RuntimeException on protocol errors
     */
    public function analyse(string $path): array
    {
        $this->ensureWorker();

        $request = json_encode(['action' => 'analyse', 'files' => [$path]]) . "\n";
        $written = @fwrite($this->workerStream, $request);
        if ($written === false || $written === 0) {
            throw new \RuntimeException('Failed to write analyse request to worker stream.');
        }

        stream_set_timeout($this->workerStream, self::ANALYSE_TIMEOUT_S);
        $line = fgets($this->workerStream);
        if ($line === false) {
            throw new \RuntimeException('Worker stream closed before sending result (analyse timeout or crash).');
        }

        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded) || ($decoded['action'] ?? null) !== 'result') {
            throw new \RuntimeException('Unexpected worker response: ' . trim($line));
        }

        return $this->extractErrors($decoded['result'] ?? []);
    }

    /**
     * Ensure the worker is running and the handshake is complete.
     * Respawns transparently if the previous worker died.
     */
    public function ensureWorker(): void
    {
        if ($this->isWarm()) {
            return;
        }

        // Clean up dead state before respawning.
        $this->teardown();

        // 1. Open TCP server on a random port.
        $this->serverSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($this->serverSocket === false) {
            throw new \RuntimeException("Cannot create TCP server: [{$errno}] {$errstr}");
        }

        $name = stream_socket_get_name($this->serverSocket, false);
        $this->serverPort = (int) explode(':', $name)[1];

        $identifier = bin2hex(random_bytes(16));

        // 2. Spawn phpstan worker subprocess.
        $this->workerProcess = $this->spawnWorker($this->serverPort, $identifier);

        // 3. Accept the worker's incoming connection.
        stream_set_blocking($this->serverSocket, false);
        $start = time();
        $client = false;
        while (time() - $start < self::HANDSHAKE_TIMEOUT_S) {
            $client = @stream_socket_accept($this->serverSocket, 0);
            if ($client !== false) {
                break;
            }
            if (!$this->isWorkerAlive()) {
                throw new \RuntimeException('PHPStan worker died before connecting.');
            }
            usleep(50_000); // 50ms poll
        }

        if ($client === false) {
            throw new \RuntimeException(
                sprintf('PHPStan worker did not connect within %ds.', self::HANDSHAKE_TIMEOUT_S)
            );
        }

        $this->workerStream = $client;
        stream_set_blocking($this->workerStream, true);
        stream_set_timeout($this->workerStream, self::HANDSHAKE_TIMEOUT_S);

        // 4. Read and verify the hello handshake.
        $hello = fgets($this->workerStream);
        if ($hello === false) {
            throw new \RuntimeException('Worker stream closed during hello handshake.');
        }

        $helloData = json_decode(trim($hello), true);
        if (
            !is_array($helloData)
            || ($helloData['action'] ?? null) !== 'hello'
            || ($helloData['identifier'] ?? null) !== $identifier
        ) {
            throw new \RuntimeException('Invalid hello handshake from worker: ' . trim($hello));
        }

        $this->handshakeDone = true;
    }

    /**
     * @return resource proc_open process resource
     */
    private function spawnWorker(int $port, string $identifier): mixed
    {
        $phpstanBin = $this->findPhpstanBin();
        $config     = getenv('MCP_PHPSTAN_CONFIG') ?: null;
        $pathsRaw   = getenv('MCP_PHPSTAN_PATHS') ?: '';
        $paths      = array_filter(array_map('trim', explode(',', $pathsRaw)));

        $args = [
            PHP_BINARY,
            $phpstanBin,
            'worker',
            '--port=' . $port,
            '--identifier=' . $identifier,
            '--memory-limit=-1',
        ];

        if ($config !== null) {
            $args[] = '--configuration=' . $config;
        }

        foreach ($paths as $p) {
            $args[] = $p;
        }

        $escaped = implode(' ', array_map('escapeshellarg', $args));

        $logStdout = sys_get_temp_dir() . '/mcp-phpstan-worker-stdout.log';
        $logStderr = sys_get_temp_dir() . '/mcp-phpstan-worker-stderr.log';

        $proc = proc_open($escaped, [
            0 => ['pipe', 'r'],
            1 => ['file', $logStdout, 'a'],
            2 => ['file', $logStderr, 'a'],
        ], $pipes);

        if (!is_resource($proc)) {
            throw new \RuntimeException('proc_open failed for phpstan worker.');
        }

        // Close stdin — worker does not read from it.
        fclose($pipes[0]);

        return $proc;
    }

    private function findPhpstanBin(): string
    {
        // phpstan/phpstan ships as a phar — classes inside it are not directly reflectable.
        // Use Composer's InstalledVersions to locate the package directory, then resolve the phar.
        if (class_exists(\Composer\InstalledVersions::class)) {
            $pkgDir = \Composer\InstalledVersions::getInstallPath('phpstan/phpstan');
            if ($pkgDir !== null) {
                $phar = realpath($pkgDir) . '/phpstan.phar';
                if (is_file($phar)) {
                    return $phar;
                }
            }
        }

        // Fallback: vendor/bin/phpstan wrapper script (present in all install modes).
        foreach ([
            __DIR__ . '/../vendor/bin/phpstan',        // local clone
            __DIR__ . '/../../../../bin/phpstan',       // project dep
        ] as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }

        throw new \RuntimeException(
            'Cannot locate phpstan binary. Run composer install, or install phpstan/phpstan.'
        );
    }

    private function isWorkerAlive(): bool
    {
        if (!is_resource($this->workerProcess)) {
            return false;
        }
        $status = proc_get_status($this->workerProcess);
        return $status !== false && $status['running'];
    }

    private function teardown(): void
    {
        $this->handshakeDone = false;

        if (is_resource($this->workerStream)) {
            @fclose($this->workerStream);
            $this->workerStream = null;
        }

        if (is_resource($this->serverSocket)) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }

        if (is_resource($this->workerProcess)) {
            @proc_terminate($this->workerProcess);
            @proc_close($this->workerProcess);
            $this->workerProcess = null;
        }
    }

    /**
     * Extract structured errors from the worker result payload.
     *
     * @param array<string,mixed> $result
     * @return array{file: string, line: int, message: string, identifier: string|null}[]
     */
    private function extractErrors(array $result): array
    {
        $errors = [];
        foreach ($result['errors'] ?? [] as $e) {
            $errors[] = [
                'file'       => $e['file'] ?? '',
                'line'       => (int) ($e['line'] ?? 0),
                'message'    => $e['message'] ?? '',
                'identifier' => $e['identifier'] ?? null,
            ];
        }
        return $errors;
    }

    public function __destruct()
    {
        $this->teardown();
    }
}
