<?php

namespace App\Base\Tenancy\Livewire\Admin;

use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Tenancy\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin tenant management: list tenants and create new ones.
 *
 * Latent tenancy: the page exists for operators; the menu only surfaces it
 * once a second tenant exists or `tenancy.show_management` is set. The
 * explicitly marked platform-operator tenant cannot be deleted.
 */
class Tenants extends Component
{
    use TogglesSort;
    use WithPagination;

    public bool $showCreateModal = false;

    public string $createName = '';

    public ?int $createParentId = null;

    public string $createStatus = 'active';

    public string $sortBy = 'id';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'id' => 'id',
        'name' => 'name',
        'status' => 'status',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: ['id' => 'asc', 'name' => 'asc', 'status' => 'asc'],
        );
    }

    public function createTenant(): void
    {
        if (! auth()->user()?->can('admin.tenancy.tenant.create')) {
            abort(403);
        }

        $validated = $this->validate([
            'createName' => ['required', 'string', 'max:255'],
            'createParentId' => ['nullable', 'integer', Rule::exists('tenants', 'id')],
            'createStatus' => ['required', 'string', Rule::in(['active', 'suspended'])],
        ]);

        Tenant::query()->create([
            'name' => $validated['createName'],
            'parent_id' => $validated['createParentId'] ?? null,
            'status' => $validated['createStatus'],
        ]);

        $this->reset('createName', 'createParentId');
        $this->createStatus = 'active';
        $this->showCreateModal = false;

        session()->flash('success', __('Tenant created.'));
    }

    public function render(): View
    {
        return view('livewire.admin.tenancy.tenants', [
            'tenants' => $this->tenantPage(),
            'parentOptions' => Tenant::query()->orderBy('id')->get(['id', 'name']),
        ]);
    }

    private function tenantPage(): LengthAwarePaginator
    {
        return Tenant::query()
            ->with('parent:id,name')
            ->withCount('children')
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);
    }
}
