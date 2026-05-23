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
 *
 * Global ignoreErrors from the project neon are applied here after receiving
 * the worker result, mirroring what PHPStan's ParallelAnalyser parent process
 * does before surfacing errors to the user.
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

    /**
     * Realpath-normalised allowlist of analysable directories, cached at worker boot
     * from MCP_PHPSTAN_PATHS. Per-call $path must resolve under one of these.
     *
     * @var list<string>|null
     */
    private ?array $allowedPaths = null;

    /**
     * Cached ignoreErrors patterns loaded once at boot via `phpstan dump-parameters`.
     * Each entry is either a string (regex) or an array with optional keys:
     *   message (regex), rawMessage (exact), identifier (exact), path (glob), paths (list of globs).
     *
     * @var list<string|array<string,mixed>>|null null = not yet loaded
     */
    private ?array $ignoreErrors = null;

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

        // Defence in depth: phpstan's worker may surface source-line context
        // for parse errors. Reject paths outside the boot --paths allowlist
        // before they reach the worker so a hostile MCP client cannot read
        // arbitrary files (e.g. /etc/passwd, ~/.ssh/*) via parse-error leaks.
        $this->assertPathAllowed($path);

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

        // Cache realpath of declared analyse paths once per worker boot — used
        // by assertPathAllowed() in analyse() to reject out-of-allowlist paths.
        $this->loadAllowedPaths();

        // Load global ignoreErrors once per worker boot so analyse() can filter them.
        $this->loadIgnoreErrors();
    }

    /**
     * Populate $allowedPaths from MCP_PHPSTAN_PATHS. Each entry is realpath-resolved;
     * unresolvable entries are dropped. Empty result = no containment (legacy / dev
     * usage where the operator did not constrain paths).
     */
    private function loadAllowedPaths(): void
    {
        $pathsRaw = getenv('MCP_PHPSTAN_PATHS') ?: '';
        $resolved = [];
        foreach (array_filter(array_map('trim', explode(',', $pathsRaw))) as $p) {
            $real = realpath($p);
            if ($real !== false) {
                $resolved[] = $real;
            }
        }
        $this->allowedPaths = array_values(array_unique($resolved));
    }

    /**
     * Reject paths that escape the boot allowlist before they reach the phpstan
     * worker. Uses realpath canonicalisation so symlinks and `..` traversal cannot
     * sneak past. Throws \RuntimeException with a deliberately terse message — we
     * do not echo the user-supplied path back in case the MCP client is hostile.
     */
    private function assertPathAllowed(string $path): void
    {
        if ($this->allowedPaths === null || $this->allowedPaths === []) {
            // No allowlist configured — preserve legacy behaviour. Operators who
            // want hardening must pass --paths at boot.
            return;
        }

        $real = realpath($path);
        if ($real === false) {
            throw new \RuntimeException('analyse: path does not exist or is not readable.');
        }

        foreach ($this->allowedPaths as $allowed) {
            if ($real === $allowed || str_starts_with($real, $allowed . DIRECTORY_SEPARATOR)) {
                return;
            }
        }

        throw new \RuntimeException('analyse: path is outside the configured --paths allowlist.');
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

        // Per-process random suffix on log file names — fixed names in /tmp are
        // a symlink-attack vector on multi-user hosts (attacker pre-creates the
        // path as a symlink to a victim-writable file; phpstan stderr gets
        // appended to it). Suffix randomises the path so pre-creation fails.
        $logSuffix = getmypid() . '-' . bin2hex(random_bytes(8));
        $logStdout = sys_get_temp_dir() . '/mcp-phpstan-worker-' . $logSuffix . '-stdout.log';
        $logStderr = sys_get_temp_dir() . '/mcp-phpstan-worker-' . $logSuffix . '-stderr.log';

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
     * Extract structured errors from the worker result payload and apply
     * the project's global ignoreErrors filter (mirrors what PHPStan's
     * ParallelAnalyser parent does before surfacing results to the user).
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
        return $this->applyIgnoreErrors($errors);
    }

    /**
     * Load global ignoreErrors once at worker boot via `phpstan dump-parameters --json`.
     * Stores the result in $this->ignoreErrors for reuse across analyse() calls.
     * On any failure (no config, phpstan error, JSON parse error) we silently store
     * an empty array — no filtering is better than crashing the daemon.
     */
    private function loadIgnoreErrors(): void
    {
        $config = getenv('MCP_PHPSTAN_CONFIG') ?: null;
        if ($config === null) {
            $this->ignoreErrors = [];
            return;
        }

        $phpstanBin = $this->findPhpstanBin();
        $args = [
            PHP_BINARY,
            $phpstanBin,
            'dump-parameters',
            '--json',
            '--memory-limit=-1',
            '--configuration=' . $config,
        ];
        $escaped = implode(' ', array_map('escapeshellarg', $args));

        $output = shell_exec($escaped . ' 2>/dev/null');
        if (!is_string($output) || $output === '') {
            $this->ignoreErrors = [];
            return;
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded) || !isset($decoded['ignoreErrors']) || !is_array($decoded['ignoreErrors'])) {
            $this->ignoreErrors = [];
            return;
        }

        $this->ignoreErrors = array_values($decoded['ignoreErrors']);
    }

    /**
     * Apply the cached global ignoreErrors to a list of structured errors.
     * Mirrors PHPStan\Analyser\Ignore\IgnoredError::shouldIgnore() logic:
     *   - string entry:                regex match on message
     *   - array with 'identifier':     exact match on identifier  (AND'd with other conditions)
     *   - array with 'message':        regex match on message      (AND'd)
     *   - array with 'rawMessage':     exact match on message      (AND'd)
     *   - array with 'path'/'paths':   fnmatch glob on file path   (AND'd)
     * All conditions within one entry are AND'd. An error matching any entry is suppressed.
     *
     * @param array{file: string, line: int, message: string, identifier: string|null}[] $errors
     * @return array{file: string, line: int, message: string, identifier: string|null}[]
     */
    private function applyIgnoreErrors(array $errors): array
    {
        if ($this->ignoreErrors === null || $this->ignoreErrors === []) {
            return $errors;
        }

        $surviving = [];
        foreach ($errors as $error) {
            $suppress = false;
            foreach ($this->ignoreErrors as $ignore) {
                if ($this->errorMatchesIgnore($error, $ignore)) {
                    $suppress = true;
                    break;
                }
            }
            if (!$suppress) {
                $surviving[] = $error;
            }
        }
        return $surviving;
    }

    /**
     * @param array{file: string, line: int, message: string, identifier: string|null} $error
     * @param string|array<string,mixed> $ignore
     */
    private function errorMatchesIgnore(array $error, string|array $ignore): bool
    {
        if (is_string($ignore)) {
            // Plain regex pattern — match against message.
            return @preg_match($ignore, $error['message']) === 1;
        }

        // Identifier check (exact).
        if (isset($ignore['identifier'])) {
            if ($error['identifier'] !== $ignore['identifier']) {
                return false;
            }
        }

        // Message regex check.
        if (isset($ignore['message'])) {
            if (@preg_match($ignore['message'], $error['message']) !== 1) {
                return false;
            }
        }

        // Raw (exact) message check.
        if (isset($ignore['rawMessage'])) {
            if ($error['message'] !== $ignore['rawMessage']) {
                return false;
            }
        }

        // Path glob check — 'path' (single) or 'paths' (list, OR'd).
        if (isset($ignore['path'])) {
            if (!fnmatch($ignore['path'], $error['file'])) {
                return false;
            }
        } elseif (isset($ignore['paths']) && is_array($ignore['paths'])) {
            $matchesAnyPath = false;
            foreach ($ignore['paths'] as $pathPattern) {
                if (fnmatch($pathPattern, $error['file'])) {
                    $matchesAnyPath = true;
                    break;
                }
            }
            if (!$matchesAnyPath) {
                return false;
            }
        }

        return true;
    }

    public function __destruct()
    {
        $this->teardown();
    }
}
