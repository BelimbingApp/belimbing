<?php

namespace App\Base\Livewire\Console\Commands;

use App\Base\Livewire\ActionInventory;
use App\Base\Livewire\ActionInventoryException;
use Illuminate\Console\Command;

final class LivewireActionsCommand extends Command
{
    protected $signature = 'blb:livewire-actions {--domain= : Installed, enabled Domain directory name} {--json : Emit JSON instead of a table}';

    protected $description = 'List callable Livewire methods and lexical test references (not coverage)';

    public function handle(ActionInventory $inventory): int
    {
        try {
            $rows = $inventory->scan($this->option('domain'));
        } catch (ActionInventoryException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Component', 'Method', 'Origin', 'Test reference'], array_map(
                fn (array $row): array => [$row['component'], $row['method'],
                    $row['module_owned'] ? 'module' : 'shared', $row['referenced_in_tests'] ? 'yes' : 'no'],
                $rows,
            ));
            $this->comment('Test references are lexical matches, including strings/comments and unrelated components; they do not prove coverage.');
        }

        return self::SUCCESS;
    }
}
