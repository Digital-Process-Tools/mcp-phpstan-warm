# mcp-phpstan-warm

> **Stop paying PHPStan's cold-start tax on every edit.**
> A warm-process [MCP](https://modelcontextprotocol.io/) server that keeps a [PHPStan](https://phpstan.org/) worker alive via its built-in TCP worker protocol. Works with every MCP client.

[![Tests](https://github.com/Digital-Process-Tools/mcp-phpstan-warm/actions/workflows/tests.yml/badge.svg)](https://github.com/Digital-Process-Tools/mcp-phpstan-warm/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/dpt/mcp-phpstan-warm.svg)](https://packagist.org/packages/dpt/mcp-phpstan-warm)
[![PHP](https://img.shields.io/badge/php-8.2%2B-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Community-brightgreen)](LICENSE)

[Why](#why) • [Install](#install) • [Use it](#use-it) • [Tools exposed](#tools-exposed) • [How it works](#how-it-works) • [FAQ](#faq)

---

## Why

[PHPStan](https://phpstan.org/) is one of the most useful tools in modern PHP — static analysis, type inference, dead code detection. It is also slow to **start**.

Every `phpstan analyse foo.php` pays the same toll: bootstrap, config parse, autoloader load, rule compilation. **1-3 seconds before a single check runs.** For agents and validators that run PHPStan after every edit, that cold-start cost dominates wall time.

`mcp-phpstan-warm` uses PHPStan's own built-in `worker` subcommand — the same TCP worker protocol PHPStan uses internally for parallel analysis. **First call boots the worker once. Every subsequent call reuses the live process.**

## Install

```bash
composer global require dpt/mcp-phpstan-warm
```

Makes `mcp-phpstan-warm` available on `$PATH`.

Requires PHP 8.2+. PHPStan ^2.0 is pulled as a real Composer dependency.

## Use it

### Claude Desktop

Edit `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "phpstan": {
      "command": "mcp-phpstan-warm",
      "args": [
        "--working-dir=/path/to/your/project",
        "--config=/path/to/your/project/phpstan.neon",
        "--paths=src,tests"
      ]
    }
  }
}
```

Restart Claude. Ask: *"Run PHPStan on src/Foo.php"*.

### Cline / Continue / Cursor / Zed / any MCP client

Same `command` + `args` shape. The server speaks plain MCP over stdio — no client-specific glue.

### Standalone

```bash
mcp-phpstan-warm \
  --working-dir=/path/to/project \
  --config=/path/to/project/phpstan.neon \
  --paths=src,tests
```

Reads MCP JSON-RPC on stdin, writes responses on stdout.

## Tools exposed

### `phpstan_analyse`

Analyse a PHP file with PHPStan.

| Argument | Type | Required | Description |
|----------|------|----------|-------------|
| `path` | string | yes | Absolute path to the PHP file to analyse |

**Important:** The file must be under one of the `--paths` declared at startup. PHPStan's worker fixes its analysed file set at boot — files outside that set will be rejected by the worker.

Returns:

```json
{
  "exit_code": 1,
  "errors": [
    {
      "file": "/path/to/src/Foo.php",
      "line": 12,
      "message": "Method Foo::bar() should return string but returns int.",
      "identifier": "return.type"
    }
  ],
  "warm_boot": true
}
```

- `exit_code`: `0` = no errors, `1` = errors found, `-1` = internal error
- `errors`: list of PHPStan errors with file, line, message, and rule identifier
- `warm_boot`: `true` = worker reused (fast path), `false` = cold boot just completed

## How it works

PHPStan ships a `worker` subcommand used internally for parallel analysis. It connects **to a TCP server** you open, sends a hello handshake, then waits for `{"action":"analyse","files":[...]}` requests and replies with `{"action":"result","result":{errors,...}}`.

`mcp-phpstan-warm` is that TCP server:

```
mcp-phpstan-warm (MCP server, TCP server)
  ├── stream_socket_server tcp://127.0.0.1:0  (random port)
  ├── proc_open: phpstan worker --port <port> --identifier <hex> -c phpstan.neon <paths>
  ├── Accept worker connection
  ├── Verify hello handshake (identifier round-trip)
  ├── Sit idle, ready
  └── On MCP tools/call phpstan_analyse(path):
        send {"action":"analyse","files":[path]}
        receive {"action":"result","result":{...}}
        format errors → return to MCP client
```

Three things worth knowing:

1. **We are the TCP server, PHPStan is the client.** `stream_socket_server('tcp://127.0.0.1:0')` opens on a random port; we pass it to `phpstan worker --port=<N>`. The worker dials us.

2. **The analysed file set is fixed at boot.** PHPStan's worker builds its dependency graph from the `<paths>` passed on the command line. Files outside those paths will fail or return dependency errors at analysis time. Pass all relevant paths via `--paths=src,tests` at startup.

3. **Worker death is handled transparently.** If `proc_get_status()` shows the worker died, the next `phpstan_analyse` call respawns it. The `warm_boot: false` flag in the response signals this happened.

## FAQ

**Does this replace `vendor/bin/phpstan`?** No. Use it from MCP clients. For one-off CLI runs the regular binary is simpler.

**Can I analyse multiple files at once?** The current tool accepts one file per call. The underlying protocol supports `"files":[...]` arrays — multi-file support can be added as a separate tool.

**Memory?** The daemon sets `memory_limit = -1`. Idle worker ≈ 60-80MB resident depending on project size and PHPStan level.

**Does it survive PHPStan version updates?** The TCP protocol (`hello` / `analyse` / `result`) is PHPStan's internal parallel transport — it's stable across patch versions. Pin a PHPStan version in your `composer.json` if you need determinism.

**Why not just subprocess `phpstan analyse` and cache the result?** You could. But you'd still pay the full cold-start on every cache miss (every new or modified file). The worker mode amortises that cost across the entire session.

## Credits

- **[PHPStan](https://phpstan.org/)** by [Ondřej Mirtes](https://github.com/ondrejmirtes) and contributors — the engine doing all the real work. If you ship PHP, [sponsor him](https://github.com/sponsors/ondrejmirtes).
- **[Model Context Protocol](https://modelcontextprotocol.io/)** by Anthropic — the protocol that makes this kind of tool integration possible.
- **[mcp/sdk](https://github.com/modelcontextprotocol/php-sdk)** — official PHP SDK, used here for stdio transport + tool discovery.

## Related

- **[PHPStan docs](https://phpstan.org/user-guide/getting-started)** — config, levels, extensions.
- **[PHPStan on Packagist](https://packagist.org/packages/phpstan/phpstan)** — the upstream package.
- **[mcp-rector-warm](https://github.com/Digital-Process-Tools/mcp-rector-warm)** — same warm-process pattern for Rector.
- **[mcp-phpunit-warm](https://github.com/Digital-Process-Tools/mcp-phpunit-warm)** — same warm-process pattern for PHPUnit.
- **[claude-supertool](https://github.com/Digital-Process-Tools/claude-supertool)** — DPT's batched-ops Claude Code companion; integrates warm-process servers as validators.

## License

Community License — see [LICENSE](LICENSE). Built by [Digital Process Tools](https://github.com/Digital-Process-Tools).
