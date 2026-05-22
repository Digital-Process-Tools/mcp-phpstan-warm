# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] — 2026-05-22

### Added

- Warm-process MCP server `mcp-phpstan-warm` keeping a PHPStan worker alive via its built-in TCP worker protocol.
- `phpstan_analyse` tool: analyse a single PHP file, returns `{exit_code, errors[], warm_boot}`.
- Worker lifecycle management: TCP server on random port, proc_open spawn, hello handshake verification, transparent respawn on worker death.
- `--working-dir`, `--config`, `--paths` CLI flags pinned at server start.
- PHPUnit unit + integration tests covering cold boot, error detection, and `warm_boot: true` on second call.
- PHP 8.2 / 8.3 / 8.4 CI matrix via GitHub Actions.
