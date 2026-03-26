<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Livewire\DatabaseTables;

use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\TableInspector;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Generic table viewer — displays contents of any registered database table.
 *
 * Read-only. Supports search across string/text columns, sortable column
 * headers, pagination, a collapsible table navigator sidebar grouped by
 * module, and foreign key relationship links.
 */
class Show extends Component
{
    private const MAX_CELL_LENGTH = 120;

    private const MAX_RECENT_TABLES = 8;

    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $tableName = '';

    public string $search = '';

    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    public bool $navigatorOpen = true;

    public bool $rawValues = false;

    /**
     * @var list<string>
     */
    public array $orphanedRegistryNotices = [];

    /**
     * Toggle the stability flag for the current table.
     */
    public function toggleStability(): void
    {
        $table = TableRegistry::query()
            ->where('table_name', $this->tableName)
            ->firstOrFail();

        if ($table->isStable()) {
            $table->markUnstable();

            return;
        }

        $table->markStable(Auth::id());
    }

    /**
     * Map stability to a badge variant.
     */
    public function stabilityVariant(bool $isStable): string
    {
        return $isStable ? 'success' : 'default';
    }

    /**
     * Initialize with the table name from the route parameter.
     *
     * Aborts with 404 if the table is not in the registry.
     * Tracks recently viewed tables in the session.
     */
    public function mount(string $tableName): void
    {
        $inspector = app(TableInspector::class);

        if (! $inspector->isRegistered($tableName)) {
            $notices = $inspector->pullOrphanedRegistryNotices();

            if ($notices !== []) {
                Session::flash('warning', implode(' ', $notices));
                $this->redirectRoute('admin.system.database-tables.index', navigate: true);

                return;
            }

            abort(404);
        }

        $this->tableName = $tableName;
        $this->navigatorOpen = session('table_navigator_open', true);
        $this->search = request()->query('search', '');
        $this->orphanedRegistryNotices = $inspector->pullOrphanedRegistryNotices();

        $this->trackRecentTable($tableName);
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

    /**
     * Toggle sort on a column. Clicking the same column flips direction.
     */
    public function sort(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /**
     * Toggle the table navigator panel visibility.
     */
    public function toggleNavigator(): void
    {
        $this->navigatorOpen = ! $this->navigatorOpen;
        session(['table_navigator_open' => $this->navigatorOpen]);
    }

    /**
     * Toggle raw value display mode.
     */
    public function toggleRawValues(): void
    {
        $this->rawValues = ! $this->rawValues;
    }

    /**
     * Format a cell value for display.
     *
     * Handles nulls, booleans, long strings, and JSON.
     * When raw mode is active, shows literal representations instead of symbols.
     */
    public function formatCell(mixed $value, string $typeName): string
    {
        if ($value === null) {
            return $this->rawValues ? 'NULL' : '—';
        }

        if (is_bool($value) || $typeName === 'bool' || $typeName === 'boolean') {
            if ($this->rawValues) {
                return $value ? 'true' : 'false';
            }

            return $value ? '✓' : '✗';
        }

        $stringValue = (string) $value;

        return mb_strlen($stringValue) > self::MAX_CELL_LENGTH
            ? mb_substr($stringValue, 0, self::MAX_CELL_LENGTH).'…'
            : $stringValue;
    }

    /**
     * Track a table in the recently viewed session list.
     */
    private function trackRecentTable(string $tableName): void
    {
        $recent = session('recent_tables', []);
        $recent = array_values(array_filter($recent, fn ($t) => $t !== $tableName));
        array_unshift($recent, $tableName);
        $recent = array_slice($recent, 0, self::MAX_RECENT_TABLES);
        session(['recent_tables' => $recent]);
    }

    public function render(): View
    {
        $inspector = app(TableInspector::class);
        $columns = $inspector->columns($this->tableName);
        $rowCount = $inspector->rowCount($this->tableName);
        $foreignKeys = $inspector->foreignKeys($this->tableName);
        $tablesGrouped = $inspector->allTablesGroupedByModule();
        $recentTables = session('recent_tables', []);

        return view('livewire.admin.system.database-tables.show', [
            'tableRegistry' => TableRegistry::query()
                ->where('table_name', $this->tableName)
                ->first(),
            'columns' => $columns,
            'rows' => $inspector->rows(
                $this->tableName,
                $this->search ?: null,
                $this->sortColumn ?: null,
                $this->sortDirection,
            ),
            'rowCount' => $rowCount,
            'foreignKeys' => $foreignKeys,
            'tablesGrouped' => $tablesGrouped,
            'recentTables' => $recentTables,
        ]);
    }
}
