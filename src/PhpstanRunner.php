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

    /**
     * Cached excludePaths globs loaded once at boot via `phpstan dump-parameters`.
     * Combined union of `excludePaths.analyse` + `excludePaths.analyseAndScan` from
     * the neon config. Used by isExcluded() to honour neon excludes — without this,
     * the warm worker force-analyzes files the CLI `phpstan analyse` would skip,
     * producing false positives on test files whose lifecycle methods (setUpBeforeClass,
     * tearDownAfterClass) aren't loaded with the right bootstrap. Closes issue #1.
     *
     * @var list<string>|null null = not yet loaded
     */
    private ?array $excludePaths = null;

    /**
     * Unix time the live worker (re)booted. The worker reflects every source file
     * as of this moment and memoises it for its whole life — re-analysing a file
     * never refreshes its reflection for OTHER files (verified). So a dependency
     * edited after this timestamp is served stale to its dependents: warm silently
     * misses an error cold catches (the cross-file sibling of claude-supertool#273).
     * The only cure is a respawn; this is the baseline staleness is measured against.
     */
    private ?int $workerBootedAt = null;

    /**
     * Files the caller has analysed through this worker, mapped to the structural
     * signature they had when the worker reflected them ({@see structuralSignature}).
     * Bounds the per-call staleness check to the working set instead of stat-ing
     * the whole --paths tree (tens of thousands of files on a real project). A
     * file here whose mtime is newer than {@see $workerBootedAt} is stale in the
     * worker and forces a respawn before the next dependent is analysed.
     *
     * @var array<string,string>
     */
    private array $analysedFiles = [];

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
        // Issue #1: honour neon excludePaths so files the CLI phpstan would
        // skip ("No files found to analyse.") don't get force-analysed by the
        // warm worker. Test files excluded from the main analysis would
        // otherwise produce confusing false positives because their
        // lifecycle bootstrap isn't loaded. We check BEFORE ensureWorker()
        // so an excluded file does not pay the worker boot cost — except
        // on the very first call (excludePaths is populated by the boot).
        if ($this->isExcluded($path)) {
            return [];
        }

        // Correctness over warmth when reflection moved under us. Two ways that
        // happens, both needing a respawn — re-analysing never refreshes memoised
        // reflection, only a fresh worker does:
        //
        //   1. A dependency we've analysed changed since boot (mtime check).
        //   2. The TARGET's own structure changed since we reflected it. PHPStan
        //      re-reads the analysed file's AST every call, so body edits are seen
        //      immediately — but the class's memoised *reflection* is not rebuilt,
        //      so an edit to a class-level @extends/@template generic keeps being
        //      checked against the shape captured at first analysis.
        //
        // Body-only edits leave the structural signature untouched, so the common
        // edit-validate-edit loop on one file stays fully warm. Checked before
        // ensureWorker() so the teardown + reboot happen in one step.
        if ($this->isWarm() && ($this->dependencyChangedSinceBoot($path) || $this->targetStructureChanged($path))) {
            $this->teardown();
        }

        $this->ensureWorker();

        // Defence in depth: phpstan's worker may surface source-line context
        // for parse errors. Reject paths outside the boot --paths allowlist
        // before they reach the worker so a hostile MCP client cannot read
        // arbitrary files (e.g. /etc/passwd, ~/.ssh/*) via parse-error leaks.
        $this->assertPathAllowed($path);

        // Re-check excludes after boot — on the cold first call, excludePaths
        // was null above, so the early gate was a no-op. Now it is populated.
        if ($this->isExcluded($path)) {
            return [];
        }

        $errors = $this->extractErrors($this->sendAnalyse($path));

        // A class created after the worker booted is invisible to it: the analysable
        // file set is fixed at boot, so the worker reports "unknown class" for a class
        // sitting on disk that a cold run resolves fine. Indistinguishable from a real
        // missing class by content alone — so pay one respawn to find out, and trust
        // the fresh worker. Bounded to a single retry per call.
        if ($this->hasUnknownClassError($errors)) {
            $this->teardown();
            $this->ensureWorker();
            $errors = $this->extractErrors($this->sendAnalyse($path));
        }

        // Remember the structure we reflected, so a later edit to it registers as
        // stale — for this file as a dependency, and for itself on re-analysis.
        $this->analysedFiles[$path] = $this->structuralSignature($path);

        return $errors;
    }

    /**
     * One analyse round-trip on the live worker stream.
     *
     * @return array<string,mixed> the worker's raw `result` payload
     * @throws \RuntimeException on protocol errors
     */
    private function sendAnalyse(string $path): array
    {
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

        $result = $decoded['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * Errors that mean "the worker does not know this class" — the signature of a
     * class file created after boot, not of a genuine mistake in the analysed file.
     *
     * @param array{file: string, line: int, message: string, identifier: string|null}[] $errors
     */
    private function hasUnknownClassError(array $errors): bool
    {
        foreach ($errors as $error) {
            if (($error['identifier'] ?? null) === 'class.notFound') {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a file we've analysed — other than $target — has an on-disk mtime
     * at or after the worker's boot, i.e. it was edited since the worker reflected
     * it. Bounded to the analysed set, so the check costs a handful of stat() calls,
     * not a walk of the whole --paths tree.
     */
    private function dependencyChangedSinceBoot(string $target): bool
    {
        if ($this->workerBootedAt === null) {
            return false;
        }
        foreach ($this->analysedFiles as $file => $_) {
            if ($file === $target) {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime >= $this->workerBootedAt) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when the target's structure changed since this worker reflected it.
     * Only meaningful for a file we have already analysed — an unseen file has no
     * memoised reflection to be stale.
     */
    private function targetStructureChanged(string $target): bool
    {
        $reflected = $this->analysedFiles[$target] ?? null;

        return $reflected !== null && $reflected !== $this->structuralSignature($target);
    }

    /**
     * Hash of everything in a file that shapes its reflection: declarations, type
     * hints, docblocks — with function bodies removed. Editing a method body leaves
     * this untouched (worker stays warm); changing a class-level generic, a
     * signature, a property type or a docblock changes it (worker respawns).
     *
     * Regular comments are dropped, docblocks are kept — PHPStan reads the latter
     * as types. Unparseable input hashes to the empty string, which compares equal
     * across calls and so never forces a respawn on its own; phplint catches those
     * files before they reach here anyway.
     */
    private function structuralSignature(string $path): string
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            return '';
        }

        $tokens = @token_get_all($source);
        $signature = '';
        $depth = 0;
        $bodyDepth = null;
        $pendingFunction = false;

        foreach ($tokens as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;

            if ($id === T_WHITESPACE || $id === T_COMMENT) {
                continue;
            }

            if ($id === T_FUNCTION) {
                $pendingFunction = true;
            }

            // Abstract and interface methods end at `;` with no body to skip.
            if ($text === ';' && $pendingFunction) {
                $pendingFunction = false;
            }

            if ($text === '{') {
                $depth++;
                if ($pendingFunction && $bodyDepth === null) {
                    $bodyDepth = $depth;
                    $pendingFunction = false;
                }
            } elseif ($text === '}') {
                if ($bodyDepth !== null && $depth === $bodyDepth) {
                    $bodyDepth = null;
                    $depth--;
                    continue;
                }
                $depth--;
            }

            if ($bodyDepth !== null) {
                continue;
            }

            $signature .= $text . '|';
        }

        return hash('sha256', $signature);
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

        // Stamp the boot moment BEFORE the worker reads any source: every file is
        // reflected as of now, so a later edit (mtime >= this) is detectably stale.
        $this->workerBootedAt = time();

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
        // Explicit override wins. Needed whenever the server and the analysed project
        // resolve to different phpstan installs — a global install of this server would
        // otherwise analyse with its own phpstan, missing every extension and custom
        // rule the project's config declares, and silently reporting far too little.
        $override = getenv('MCP_PHPSTAN_PHPSTAN_BIN') ?: '';
        if ($override !== '') {
            $resolved = realpath($override);
            if ($resolved === false || !is_file($resolved)) {
                throw new \RuntimeException('MCP_PHPSTAN_PHPSTAN_BIN does not point at a file: ' . $override);
            }

            return $resolved;
        }

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
        // The staleness baseline belongs to the dead worker — a respawn reflects
        // every file fresh, so reset it together with the analysed-file set.
        $this->workerBootedAt = null;
        $this->analysedFiles = [];

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

        // Issue #1: extract excludePaths in the same pass — dump-parameters
        // already gives us both. PHPStan stores them under
        // `excludePaths.analyse` and `excludePaths.analyseAndScan`; we union
        // them since a file in either list should be skipped by analyse().
        $excludes = [];
        $ex = $decoded['excludePaths'] ?? null;
        if (is_array($ex)) {
            foreach (['analyse', 'analyseAndScan'] as $key) {
                if (isset($ex[$key]) && is_array($ex[$key])) {
                    foreach ($ex[$key] as $glob) {
                        if (is_string($glob) && $glob !== '') {
                            $excludes[] = $glob;
                        }
                    }
                }
            }
        }
        $this->excludePaths = array_values(array_unique($excludes));
    }

    /**
     * Match a file path against the cached excludePaths globs (issue #1).
     *
     * PHPStan's neon entries can be absolute or relative (`tests/*`). We match
     * both shapes:
     *   - Absolute exclude vs absolute path: direct fnmatch.
     *   - Relative exclude vs absolute path: fnmatch against any path suffix.
     *   - Otherwise: fnmatch the input as-is.
     *
     * Returns false on an empty allowlist so legacy callers keep working.
     */
    private function isExcluded(string $path): bool
    {
        if ($this->excludePaths === null || $this->excludePaths === []) {
            return false;
        }

        $real = realpath($path);
        $candidates = [$path];
        if ($real !== false && $real !== $path) {
            $candidates[] = $real;
        }

        foreach ($this->excludePaths as $glob) {
            $isAbsolute = ($glob !== '' && $glob[0] === DIRECTORY_SEPARATOR);
            foreach ($candidates as $candidate) {
                if (fnmatch($glob, $candidate)) {
                    return true;
                }
                // Relative glob like `tests/unit/*` should match the trailing
                // segment of an absolute path. We test by stripping leading
                // path components and re-matching.
                if (!$isAbsolute) {
                    $parts = explode(DIRECTORY_SEPARATOR, ltrim($candidate, DIRECTORY_SEPARATOR));
                    for ($i = 0; $i < count($parts); $i++) {
                        $tail = implode(DIRECTORY_SEPARATOR, array_slice($parts, $i));
                        if (fnmatch($glob, $tail)) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
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
