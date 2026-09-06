<?php

namespace App\Base\Foundation\Console\Commands;

use App\Base\Foundation\Services\ModuleCheck;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Domain-author smoke check: one Module plus its requires-modules (#657).
 */
#[AsCommand(name: 'blb:module-check')]
class ModuleCheckCommand extends Command
{
    protected $description = 'Compose one Module with its declared dependencies and report routes, tables, bindings, and refusals';

    protected $signature = 'blb:module-check
        {module : Stable Module id (for example people/training)}
        {--json : Emit machine-readable JSON}';

    public function handle(ModuleCheck $check): int
    {
        $report = $check->inspect((string) $this->argument('module'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->output->write($check->render($report));
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
