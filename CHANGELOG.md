# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.6.0] — 2026-06-21

### Fixed

- **Cross-file reflection staleness: warm no longer silently misses errors cold catches.** The warm worker memoises a class's reflection for its whole life, and re-analysing a file only re-reads *that file's* AST — it never refreshes the reflection of its **dependencies**. So after a dependency was edited (e.g. a method removed), re-analysing a dependent that calls it returned **0 errors**, while a cold `phpstan` run reported the now-undefined call. Unlike the same-file edit case (already handled — phpstan re-reads the analysed file), this is cross-file and was silent. The sibling of the loud rector failure in claude-supertool#273. PHPStan's worker exposes no per-class invalidation, so `PhpstanRunner` now **respawns the worker when a non-target file it has analysed changed since the worker booted** (`workerBootedAt` + an analysed-file set, checked before `ensureWorker()`). The respawn is scoped: the analysis **target is excluded** (phpstan re-reads it anyway), so iterating on a single file never respawns and stays fully warm — the cold boot is paid only when you switch to a different file after editing a dependency. The staleness check stats only the analysed working set, not the whole `--paths` tree.

### Added

- `testStaleDependencyIsCaughtWhenDependentReanalysed` — integration regression: edit a dependency on disk, then re-analyse a dependent through the same warm worker and assert the now-undefined call is reported (proven red without the respawn, green with it).

## [0.5.0] — 2026-05-23

### Fixed

- **Honour neon `excludePaths` (closes [#1](https://github.com/Digital-Process-Tools/mcp-phpstan-warm/issues/1)).** The warm worker previously force-analysed files the CLI `phpstan analyse` would skip — producing false positives on test files whose lifecycle methods (`setUpBeforeClass`, `tearDownAfterClass`) weren't loaded with the project bootstrap. `PhpstanRunner` now caches `excludePaths.analyse` + `excludePaths.analyseAndScan` at boot via the same `dump-parameters --json` round-trip as `ignoreErrors`, and short-circuits `analyse()` with an empty errors list when the file matches an exclude glob. Matching covers absolute globs, relative globs (`tests/unit/*`) against any suffix of an absolute path, and `fnmatch` against both raw and realpath-resolved candidates. Check fires BEFORE `ensureWorker()` on warm calls so excluded files pay no boot cost.

### Added

- Unit tests `PhpstanRunnerExcludeTest` (5 cases) — absolute glob match, relative glob match, empty-list passthrough, short-circuit with allowlist, short-circuit without allowlist (legacy callers).

## [0.4.0] — 2026-05-23

### Security

- **Path containment on `phpstan_analyse`.** The MCP `phpstan_analyse(path)` tool previously delegated path validation entirely to the phpstan worker. A hostile MCP client could request analysis of arbitrary files (e.g. `/etc/passwd`, `~/.ssh/*`), and worker parse-error messages could echo source-line context from those files — an info-disclosure leak. `PhpstanRunner::analyse()` now realpath-canonicalises `$path` and rejects anything not under one of the boot `--paths` directories. Empty allowlist preserves legacy behaviour (operators who didn't pass `--paths` are unaffected).
- **Randomised worker log filenames.** `/tmp/mcp-phpstan-worker-{stdout,stderr}.log` had fixed names — on multi-user hosts an attacker could pre-create them as symlinks to a victim-writable file and the worker would append phpstan stderr through the symlink. Filenames now include `getmypid() . '-' . bin2hex(random_bytes(8))` so pre-creation fails.

### Added

- `PhpstanRunner::loadAllowedPaths()` (private) caches realpath-resolved `--paths` once per worker boot.
- `PhpstanRunner::assertPathAllowed()` (private) enforces the allowlist before sending the analyse request to the worker.
- Unit tests `testAssertPathAllowedRejectsOutsidePaths` + `testAssertPathAllowedAllowsAllWhenAllowlistEmpty`.

## [0.3.0] — 2026-05-22

### Fixed

- Global `ignoreErrors` patterns from the project neon are now applied to worker results,
  mirroring what PHPStan's own `ParallelAnalyser` parent process does. Previously, errors
  suppressed via `ignoreErrors` (by identifier, regex, or path) leaked through to the MCP
  tool output. The filter is loaded once at worker boot via `phpstan dump-parameters --json`
  and cached for the lifetime of the daemon.

## [0.1.0] — 2026-05-22

### Added

- Warm-process MCP server `mcp-phpstan-warm` keeping a PHPStan worker alive via its built-in TCP worker protocol.
- `phpstan_analyse` tool: analyse a single PHP file, returns `{exit_code, errors[], warm_boot}`.
- Worker lifecycle management: TCP server on random port, proc_open spawn, hello handshake verification, transparent respawn on worker death.
- `--working-dir`, `--config`, `--paths` CLI flags pinned at server start.
- PHPUnit unit + integration tests covering cold boot, error detection, and `warm_boot: true` on second call.
- PHP 8.2 / 8.3 / 8.4 CI matrix via GitHub Actions.
