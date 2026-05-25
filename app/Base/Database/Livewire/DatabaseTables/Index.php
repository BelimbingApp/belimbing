<?php

namespace App\Base\Database\Livewire\DatabaseTables;

use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\IncubatingSchemaPreflight;
use App\Base\Database\Services\TableInspector;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
        $tables = $this->tableQuery()
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        $details = app(IncubatingSchemaPreflight::class)->schemaDetailsForTables(
            $tables->getCollection()->pluck('table_name')->all(),
        );

        $tables->getCollection()->transform(function (TableRegistry $table) use ($details): TableRegistry {
            $detail = $details[$table->table_name] ?? ['state' => 'unknown', 'source_declared' => false, 'deprecated_pattern' => null];
            $table->schema_state = $detail['state'];
            $table->source_declared = $detail['source_declared'];
            $table->deprecated_pattern = $detail['deprecated_pattern'];

            return $table;
        });

        return view('livewire.admin.system.database-tables.index', [
            'tables' => $tables,
        ]);
    }

    private function tableQuery(): Builder
    {
        return TableRegistry::query()
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = trim($this->search);

                $query->where(function (Builder $nested) use ($search): void {
                    if (str_contains($search, '*') || str_contains($search, '?')) {
                        $pattern = $this->wildcardToSqlLike($search);

                        $nested->where('table_name', 'like', $pattern)
                            ->orWhere('module_name', 'like', $pattern)
                            ->orWhere('migration_file', 'like', $pattern);

                        return;
                    }

                    $nested->where('table_name', 'like', '%'.$search.'%')
                        ->orWhere('module_name', 'like', '%'.$search.'%')
                        ->orWhere('migration_file', 'like', '%'.$search.'%');
                });
            });
    }

    private function wildcardToSqlLike(string $pattern): string
    {
        $pattern = addcslashes($pattern, '\\%_');
        $pattern = str_replace(['*', '?'], ['%', '_'], $pattern);

        return $pattern;
    }
}
