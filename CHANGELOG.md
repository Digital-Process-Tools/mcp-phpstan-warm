# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
