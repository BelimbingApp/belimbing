<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:db:share:scopes')]
class DataShareScopesCommand extends Command
{
    protected $signature = 'blb:db:share:scopes {--json : Emit machine-readable JSON}';

    protected $description = 'List Base-discovered module and table export scopes';

    public function handle(DataShareScopeCatalog $catalog): int
    {
        $scopes = array_map(fn ($scope): array => [
            'name' => $scope->name,
            'label' => $scope->label,
            'module_path' => $scope->modulePath,
            'tables' => array_map(fn ($table): array => [
                'name' => $table->table,
                'primary_key' => $table->primaryKeyColumns,
                'shareable' => $table->primaryKeyColumns !== [],
            ], $scope->tables),
        ], $catalog->scopes());

        if ($this->option('json')) {
            $this->line(json_encode(array_values($scopes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        foreach ($scopes as $scope) {
            $this->components->info($scope['label'].' ('.$scope['name'].')');
            $this->components->twoColumnDetail('Tables', implode(', ', array_column($scope['tables'], 'name')));
        }

        return self::SUCCESS;
    }
}
