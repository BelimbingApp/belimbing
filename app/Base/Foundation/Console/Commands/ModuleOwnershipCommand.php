<?php

namespace App\Base\Foundation\Console\Commands;

use App\Base\Foundation\Services\ModuleCheck;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:module-ownership')]
class ModuleOwnershipCommand extends Command
{
    protected $description = 'Refuse table and route ownership collisions across composed Domain Modules';

    protected $signature = 'blb:module-ownership
        {--json : Emit machine-readable JSON}';

    public function handle(ModuleCheck $check): int
    {
        $report = $check->inspectDomainOwnership();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->output->write($check->renderDomainOwnership($report));
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
