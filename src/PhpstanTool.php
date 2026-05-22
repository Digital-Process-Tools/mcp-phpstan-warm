<?php

declare(strict_types=1);

namespace Dpt\McpPhpstanWarm;

use Mcp\Capability\Attribute\McpTool;

final class PhpstanTool
{
    private PhpstanRunner $runner;

    public function __construct(?PhpstanRunner $runner = null)
    {
        $this->runner = $runner ?? new PhpstanRunner();
    }

    /**
     * Analyse a PHP file with PHPStan. Config and paths are pinned at server startup
     * (--config and --paths flags passed to mcp-phpstan-warm).
     *
     * The file must be under one of the paths declared at startup — PHPStan's worker
     * fixes its analysed file set at boot. Files outside that set will be rejected.
     *
     * @param string $path Absolute path to the PHP file to analyse
     * @return array{
     *   exit_code: int,
     *   errors: array<array{file: string, line: int, message: string, identifier: string|null}>,
     *   warm_boot: bool,
     *   error?: string,
     *   error_class?: string,
     *   trace?: string
     * }
     */
    #[McpTool(name: 'phpstan_analyse', description: 'Analyse a PHP file with PHPStan. Server-pinned config and paths.')]
    public function analyse(string $path): array
    {
        $warmBoot = $this->runner->isWarm();

        try {
            $errors = $this->runner->analyse($path);

            return [
                'exit_code' => count($errors) > 0 ? 1 : 0,
                'errors'    => $errors,
                'warm_boot' => $warmBoot,
            ];
        } catch (\Throwable $e) {
            return [
                'exit_code'   => -1,
                'errors'      => [],
                'warm_boot'   => $warmBoot,
                'error'       => $e->getMessage(),
                'error_class' => $e::class,
                'trace'       => $e->getTraceAsString(),
            ];
        }
    }
}
