<?php

namespace App\Base\Database\Livewire\DataOperations;

use App\Base\Database\Models\DataOperationRun;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Central data operations history (admin/system/data-operations): every mass
 * data operation — mirror push/force-push/pull and AX/IBP imports — from the
 * shared ledger, newest first. Read-only: history is written by the operations
 * themselves, never edited here. Mirror and IBP link into this surface.
 */
class Index extends Component
{
    use WithPagination;

    /** @var list<string> */
    private const MIRROR_TYPES = ['mirror_push', 'mirror_force_push', 'mirror_pull'];

    #[Url]
    public string $type = 'all';

    #[Url]
    public string $status = 'all';

    /** Deep-link target: expands this run on load (e.g. from a completion summary). */
    #[Url]
    public ?int $run = null;

    public ?int $selectedRunId = null;

    public function mount(): void
    {
        $this->selectedRunId = $this->run;
    }

    public function updatingType(): void
    {
        $this->resetPage();
        $this->selectedRunId = null;
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
        $this->selectedRunId = null;
    }

    public function toggle(int $runId): void
    {
        $this->selectedRunId = $this->selectedRunId === $runId ? null : $runId;
    }

    public function render(): View
    {
        $runs = DataOperationRun::query()
            ->when($this->type === 'mirror', fn (Builder $q) => $q->whereIn('operation_type', self::MIRROR_TYPES))
            ->when($this->type === 'import', fn (Builder $q) => $q->where('operation_type', 'ax_import'))
            ->when($this->status !== 'all', fn (Builder $q) => $q->where('status', $this->status))
            ->latest('id')
            ->paginate(20);

        $selected = $this->selectedRunId !== null
            ? DataOperationRun::query()->with('tables')->find($this->selectedRunId)
            : null;

        return view('livewire.admin.system.data-operations.index', [
            'runs' => $runs,
            'selected' => $selected,
        ]);
    }
}
