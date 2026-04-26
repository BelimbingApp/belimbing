<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Livewire\DatabaseTables;

use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\TableInspector;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
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
     * Toggle the stability flag for a table.
     */
    public function toggleStability(int $id): void
    {
        $table = TableRegistry::query()->findOrFail($id);

        if ($table->isStable()) {
            $table->markUnstable();
        } else {
            $table->markStable(Auth::id());
        }
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
     * Map stability to a badge variant.
     */
    public function stabilityVariant(bool $isStable): string
    {
        return $isStable ? 'success' : 'default';
    }

    /**
     * Sort by the given column, toggling direction if already active.
     */
    public function sort(string $column): void
    {
        $allowed = ['table_name', 'module_name', 'migration_file', 'is_stable', 'stabilized_at'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.system.database-tables.index', [
            'tables' => TableRegistry::query()
                ->when($this->search, function ($query, $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('table_name', 'like', '%'.$search.'%')
                            ->orWhere('module_name', 'like', '%'.$search.'%')
                            ->orWhere('migration_file', 'like', '%'.$search.'%');
                    });
                })
                ->orderBy($this->sortBy, $this->sortDir)
                ->paginate(25),
        ]);
    }
}
