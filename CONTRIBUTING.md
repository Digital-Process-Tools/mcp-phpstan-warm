# Contributing

Thanks for the interest. This project is small and intentionally focused.

## Reporting issues

Open a GitHub issue with:

- PHPStan version (`composer show phpstan/phpstan`)
- PHP version (`php -v`)
- MCP client (Claude Desktop, Cline, ...)
- Repro: minimal `phpstan.neon` + the failing command

## Pull requests

1. Fork, branch from `main`.
2. Add a test for the change (`tests/Unit` for logic, `tests/Integration` for end-to-end stdio behaviour).
3. Run the suite:
   ```bash
   ./vendor/bin/phpunit --no-coverage
   ```
4. Open the PR with a one-paragraph summary of the change.

## What we'll merge

- Bug fixes with a regression test.
- PHPStan version compatibility fixes.
- New MCP tools that have a clear use case from an MCP client.
- Doc / README improvements.

## What we won't merge

- Features that embed PHPStan in-process — the whole point is TCP worker mode; in-process would break on PHPStan's prefixed namespaces.
- Wrappers that just shell out to `vendor/bin/phpstan analyse` cold — defeats the warm guarantee.

## Local development

```bash
git clone https://github.com/Digital-Process-Tools/mcp-phpstan-warm.git
cd mcp-phpstan-warm
composer install
./vendor/bin/phpunit --no-coverage
```

Smoke test the binary against the fixture project:

```bash
bin/mcp-phpstan-warm \
  --working-dir=tests/Fixtures/project \
  --config=tests/Fixtures/project/phpstan.neon \
  --paths=tests/Fixtures/project/src
# (then paste MCP JSON-RPC on stdin)
```
