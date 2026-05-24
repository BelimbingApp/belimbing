<?php

namespace App\Base\Database\Livewire\DatabaseTables;

use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\TableInspector;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'table_name';

    public string $sortDir = 'asc';

    /**
     * @var list<string>
     */
    public array $orphanedRegistryNotices = [];

    public function mount(TableInspector $inspector): void
    {
        $this->orphanedRegistryNotices = $inspector->reconcileRegistry();
    }

    /**
     * Dismiss a reconciliation notice.
     */
    public function dismissNotice(int $index): void
    {
        if (! array_key_exists($index, $this->orphanedRegistryNotices)) {
            return;
        }

        unset($this->orphanedRegistryNotices[$index]);
        $this->orphanedRegistryNotices = array_values($this->orphanedRegistryNotices);
    }

    public function schemaStateVariant(string $schemaState): string
    {
        return match ($schemaState) {
            'incubating' => 'warning',
            'infrastructure' => 'default',
            'stable' => 'success',
            default => 'default',
        };
    }

    /**
     * Sort by the given column, toggling direction if already active.
     */
    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: ['table_name', 'module_name', 'migration_file'],
        );
    }

    public function render(): View
    {
        $tables = TableRegistry::query()
            ->when($this->search, function ($query, $search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('table_name', 'like', '%'.$search.'%')
                        ->orWhere('module_name', 'like', '%'.$search.'%')
                        ->orWhere('migration_file', 'like', '%'.$search.'%');
                });
            })
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        $schemaStates = app(IncubatingSchemaInspector::class)->schemaStatesForTables(
            $tables->getCollection()->pluck('table_name')->all(),
        );

        $tables->getCollection()->transform(function (TableRegistry $table) use ($schemaStates): TableRegistry {
            $table->schema_state = $schemaStates[$table->table_name] ?? 'unknown';

            return $table;
        });

        return view('livewire.admin.system.database-tables.index', [
            'tables' => $tables,
        ]);
    }
}
