<?php

namespace App\Base\Database\Livewire\DatabaseTables;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Database\Exceptions\DataShareCaptureException;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\DataShare\DiagnosticRowCapture;
use App\Base\Database\Services\TableInspector;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Support\Str as BlbStr;
use App\Modules\Core\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

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
    use TogglesSort;
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
     * Primary key values of rows selected for diagnostic capture.
     *
     * @var list<string>
     */
    public array $selectedRowIds = [];

    public bool $showCaptureModal = false;

    /**
     * @var array{tables: list<array{table: string, depth: int, row_count: int, redacted_columns: list<string>}>, total_rows: int, selected_rows: int, payload_size_bytes: int, preview_sha256: string, source: array<string, mixed>}|null
     */
    public ?array $capturePreview = null;

    public ?string $captureStatusMessage = null;

    public ?string $captureStatusVariant = null;

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
        $allowedColumns = collect(app(TableInspector::class)->columns($this->tableName))
            ->pluck('name')
            ->all();

        $this->toggleSort(
            column: $column,
            allowedColumns: $allowedColumns,
            sortByProperty: 'sortColumn',
            sortDirProperty: 'sortDirection',
        );
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

        return BlbStr::preview($stringValue, self::MAX_CELL_LENGTH);
    }

    /**
     * Check whether the column type should be treated as a timezone-aware datetime.
     */
    public function isTimestampType(string $typeName): bool
    {
        $normalized = strtolower($typeName);

        return str_contains($normalized, 'timestamp') || str_contains($normalized, 'datetime');
    }

    /**
     * Normalize a raw database timestamp/datetime value to a UTC ISO string.
     */
    public function timestampIso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')
            ->utc()
            ->toIso8601String();
    }

    /**
     * Build a stable row key from the row payload instead of loop position.
     *
     * Sorting can reorder rows while keeping the same loop indexes. Keying by
     * index lets Livewire reuse DOM nodes for different records, which breaks
     * client-side datetime formatting hooks that depend on the row payload.
     */
    public function rowKey(mixed $row, int $index): string
    {
        $encoded = json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return 'row-'.$index;
        }

        return 'row-'.md5($encoded);
    }

    /**
     * Clear the diagnostic capture row selection.
     */
    public function clearSelection(): void
    {
        $this->selectedRowIds = [];
    }

    /**
     * Resolve the dependency closure preview and open the capture dialog.
     */
    public function openCaptureDialog(?DiagnosticRowCapture $capture = null): void
    {
        $capture ??= app(DiagnosticRowCapture::class);

        $this->requireCapability('admin.system.data-share.create');
        $this->captureStatusMessage = null;

        try {
            $this->capturePreview = $capture->preview($this->tableName, $this->selectedRowIds);
        } catch (DataShareCaptureException $e) {
            $this->flashCaptureStatus($e->getMessage(), 'warning');

            return;
        } catch (Throwable $e) {
            report($e);
            $this->flashCaptureStatus(__('Could not resolve dependencies. Review the application logs and try again.'), 'danger');

            return;
        }

        $this->showCaptureModal = true;
    }

    public function closeCaptureDialog(): void
    {
        $this->showCaptureModal = false;
        $this->capturePreview = null;
    }

    /**
     * Write the reviewed diagnostic capture package to protected storage.
     */
    public function createCapturePackage(?DiagnosticRowCapture $capture = null): void
    {
        $capture ??= app(DiagnosticRowCapture::class);

        $this->requireCapability('admin.system.data-share.create');

        try {
            $result = $capture->capture(
                $this->tableName,
                $this->selectedRowIds,
                $this->captureTrigger(),
                (string) ($this->capturePreview['preview_sha256'] ?? ''),
            );
        } catch (DataShareCaptureException $e) {
            $this->closeCaptureDialog();
            $this->flashCaptureStatus($e->getMessage(), 'warning');

            return;
        } catch (Throwable $e) {
            report($e);
            $this->flashCaptureStatus(__('Capture failed. Review the application logs and try again.'), 'danger');

            return;
        }

        $this->closeCaptureDialog();
        $this->clearSelection();
        $this->flashCaptureStatus(
            __(':id created (:rows rows, :size bytes) at :path. Development import only.', [
                'id' => $result['package_id'],
                'rows' => $result['total_rows'],
                'size' => $result['size_bytes'],
                'path' => $result['path'],
            ]),
            'success',
        );
    }

    private function flashCaptureStatus(string $message, string $variant): void
    {
        $this->captureStatusMessage = $message;
        $this->captureStatusVariant = $variant;
    }

    private function captureTrigger(): string
    {
        $user = auth()->user();
        $userId = $user instanceof User ? (int) $user->id : 0;

        return "ui:user={$userId}";
    }

    private function requireCapability(string $capability): void
    {
        if (! $this->capabilityAllows($capability)) {
            abort(403, "Capability '{$capability}' is required.");
        }
    }

    private function capabilityAllows(string $capability): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return app(AuthorizationService::class)->can(Actor::forUser($user), $capability)->allowed;
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
        $indexes = $inspector->indexes($this->tableName);
        $rowCount = $inspector->rowCount($this->tableName);
        $foreignKeys = $inspector->foreignKeys($this->tableName);
        $migrationSource = $inspector->migrationSource($this->tableName);
        $tablesGrouped = $inspector->allTablesGroupedByModule();
        $recentTables = session('recent_tables', []);
        $tableRegistry = TableRegistry::query()
            ->where('table_name', $this->tableName)
            ->first();
        $capturePrimaryKey = app(DiagnosticRowCapture::class)->primaryKeyColumn($this->tableName);
        $canCapture = $capturePrimaryKey !== null
            && $this->capabilityAllows('admin.system.data-share.create');

        return view('livewire.admin.system.database-tables.show', [
            'tableRegistry' => $tableRegistry,
            'schemaState' => $tableRegistry === null
                ? 'unknown'
                : app(IncubatingSchemaInspector::class)->tableSchemaState($this->tableName),
            'columns' => $columns,
            'indexes' => $indexes,
            'rows' => $inspector->rows(
                $this->tableName,
                $this->search ?: null,
                $this->sortColumn ?: null,
                $this->sortDirection,
            ),
            'rowCount' => $rowCount,
            'foreignKeys' => $foreignKeys,
            'migrationSource' => $migrationSource,
            'tablesGrouped' => $tablesGrouped,
            'recentTables' => $recentTables,
            'canCapture' => $canCapture,
            'capturePrimaryKey' => $capturePrimaryKey,
        ]);
    }
}
