# Security

If you find a security issue, please email **security@digitalprocesstools.com** before opening a public issue.

The server runs a PHPStan worker inside a long-lived PHP process and processes files under the configured `--paths`. Do not point it at directories containing untrusted code.
