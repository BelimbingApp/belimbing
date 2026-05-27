<?php

namespace App\Base\Database\Livewire\SchemaIncubation;

use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\IncubatingSchemaPreflight;
use App\Base\Database\Services\MigrationIncubationManager;
use App\Base\Database\Services\TableInspector;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use TogglesSort;
    use WithPagination;

    public string $incubatingSearch = '';

    public string $incubatingModule = '';

    public string $search = '';

    public string $sortBy = 'table_name';

    public string $sortDir = 'asc';

    /**
     * @var list<string>
     */
    public array $selectedIncubatingTables = [];

    public bool $selectIncubatingPage = false;

    /**
     * @var list<string>
     */
    public array $selectedSearchTables = [];

    public bool $selectSearchPage = false;

    /**
     * @var list<string>
     */
    public array $orphanedRegistryNotices = [];

    public function updatedIncubatingSearch(): void
    {
        $this->resetIncubatingSelection();
        $this->resetPage('incubatingPage');
    }

    public function updatedIncubatingModule(): void
    {
        $this->resetIncubatingSelection();
        $this->resetPage('incubatingPage');
    }

    public function updatedSearch(): void
    {
        $this->resetSearchSelection();
        $this->resetPage('searchPage');
    }

    public function mount(TableInspector $inspector): void
    {
        $this->orphanedRegistryNotices = $inspector->reconcileRegistry();
    }

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

    public function updatedSelectIncubatingPage(bool $value): void
    {
        $this->selectedIncubatingTables = $value ? $this->visibleIncubatingTableNames() : [];
    }

    public function updatedSelectSearchPage(bool $value): void
    {
        $this->selectedSearchTables = $value ? $this->visibleSearchTableNames() : [];
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: ['table_name', 'module_name', 'migration_file'],
        );
    }

    public function moveSelectedToIncubation(): void
    {
        if ($this->selectedSearchTables === []) {
            session()->flash('warning', __('Select at least one table first.'));

            return;
        }

        $details = app(IncubatingSchemaPreflight::class)->schemaDetailsForTables($this->selectedSearchTables);
        $toUpdate = [];
        $skipped = [];

        foreach ($this->selectedSearchTables as $tableName) {
            $detail = $details[$tableName] ?? ['state' => 'unknown', 'source_declared' => false];

            if (($detail['state'] ?? 'unknown') === 'infrastructure') {
                $skipped[] = $tableName.' (infrastructure)';
                continue;
            }

            if (($detail['source_declared'] ?? false) === true) {
                $skipped[] = $tableName.' (already source-declared)';
                continue;
            }

            $toUpdate[] = $tableName;
        }

        $result = app(MigrationIncubationManager::class)->markTablesIncubating($toUpdate);
        $this->resetSearchSelection();
        $this->resetIncubationPagination();
        $this->flashIncubationResult(
            action: 'moved to incubation',
            changed: $result['updated'],
            skipped: array_merge($skipped, $result['skipped']),
            emptyMessage: __('No selected tables were eligible to move into source incubation.'),
        );
    }

    public function removeSelectedFromIncubation(): void
    {
        if ($this->selectedIncubatingTables === []) {
            session()->flash('warning', __('Select at least one table first.'));

            return;
        }

        $details = app(IncubatingSchemaPreflight::class)->schemaDetailsForTables($this->selectedIncubatingTables);
        $toUpdate = [];
        $skipped = [];

        foreach ($this->selectedIncubatingTables as $tableName) {
            $detail = $details[$tableName] ?? ['state' => 'unknown', 'source_declared' => false];

            if (($detail['source_declared'] ?? false) === true) {
                $toUpdate[] = $tableName;
                continue;
            }

            $skipped[] = $tableName.' (not source-declared)';
        }

        $result = app(MigrationIncubationManager::class)->unmarkTablesIncubating($toUpdate);
        $this->resetIncubatingSelection();
        $this->resetIncubationPagination();
        $this->flashIncubationResult(
            action: 'removed from incubation',
            changed: $result['updated'],
            skipped: array_merge($skipped, $result['skipped']),
            emptyMessage: __('No selected tables could be removed from source incubation.'),
        );
    }

    public function render(): View
    {
        $incubatingTables = $this->incubatingTableQuery()
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25, pageName: 'incubatingPage');

        $searchTables = $this->searchResultsQuery()
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25, pageName: 'searchPage');

        $allVisible = array_values(array_unique(array_merge(
            $incubatingTables->getCollection()->pluck('table_name')->all(),
            $searchTables->getCollection()->pluck('table_name')->all(),
        )));

        $details = app(IncubatingSchemaPreflight::class)->schemaDetailsForTables($allVisible);

        $transform = function (TableRegistry $table) use ($details): TableRegistry {
            $detail = $details[$table->table_name] ?? ['state' => 'unknown', 'source_declared' => false];
            $table->schema_state = $detail['state'];
            $table->source_declared = $detail['source_declared'];

            return $table;
        };

        $incubatingTables->getCollection()->transform($transform);
        $searchTables->getCollection()->transform($transform);

        return view('livewire.admin.system.database-incubation.index', [
            'incubatingTables' => $incubatingTables,
            'incubatingModules' => $this->incubatingModuleOptions(),
            'searchTables' => $searchTables,
        ]);
    }

    private function incubatingTableQuery(): Builder
    {
        return TableRegistry::query()
            ->whereIn('table_name', $this->sourceIncubatingTableNames())
            ->when($this->incubatingModule !== '', fn (Builder $query) => $query->where('module_name', $this->incubatingModule))
            ->when(trim($this->incubatingSearch) !== '', function (Builder $query): void {
                $query->where('table_name', 'like', '%'.trim($this->incubatingSearch).'%');
            });
    }

    private function searchResultsQuery(): Builder
    {
        $search = trim($this->search);

        if ($search === '') {
            return TableRegistry::query()->whereRaw('1 = 0');
        }

        $excluded = $this->sourceIncubatingTableNames();

        return TableRegistry::query()
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('table_name', $excluded))
            ->whereNotIn('table_name', TableRegistry::INFRASTRUCTURE_TABLES)
            ->where(function (Builder $nested) use ($search): void {
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
    }

    /**
     * @return list<string>
     */
    private function visibleIncubatingTableNames(): array
    {
        return $this->incubatingTableQuery()
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25, pageName: 'incubatingPage')
            ->getCollection()
            ->pluck('table_name')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function visibleSearchTableNames(): array
    {
        return $this->searchResultsQuery()
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25, pageName: 'searchPage')
            ->getCollection()
            ->pluck('table_name')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function sourceIncubatingTableNames(): array
    {
        $details = app(IncubatingSchemaPreflight::class)->schemaDetailsForTables(
            TableRegistry::query()->pluck('table_name')->all(),
        );

        return array_values(array_keys(array_filter(
            $details,
            fn (array $detail): bool => ($detail['source_declared'] ?? false) === true,
        )));
    }

    /**
     * @return list<string>
     */
    private function incubatingModuleOptions(): array
    {
        return TableRegistry::query()
            ->whereIn('table_name', $this->sourceIncubatingTableNames())
            ->whereNotNull('module_name')
            ->pluck('module_name')
            ->filter(fn (mixed $module): bool => is_string($module) && $module !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function wildcardToSqlLike(string $pattern): string
    {
        $pattern = addcslashes($pattern, '\\%_');
        $pattern = str_replace(['*', '?'], ['%', '_'], $pattern);

        return $pattern;
    }

    /**
     * @param  list<string>  $changed
     * @param  list<string>  $skipped
     */
    private function flashIncubationResult(string $action, array $changed, array $skipped, string $emptyMessage): void
    {
        if ($changed !== []) {
            session()->flash('status', __('Source incubation updated: :action :tables.', [
                'action' => $action,
                'tables' => implode(', ', $changed),
            ]));
        } elseif ($skipped === []) {
            session()->flash('warning', $emptyMessage);
        }

        if ($skipped !== []) {
            session()->flash('warning', __('Skipped: :tables', [
                'tables' => implode('; ', $skipped),
            ]));
        }
    }

    private function resetIncubatingSelection(): void
    {
        $this->selectedIncubatingTables = [];
        $this->selectIncubatingPage = false;
    }

    private function resetSearchSelection(): void
    {
        $this->selectedSearchTables = [];
        $this->selectSearchPage = false;
    }

    private function resetIncubationPagination(): void
    {
        $this->resetPage('incubatingPage');
        $this->resetPage('searchPage');
    }
}
