<?php

namespace App\Base\Tenancy\Livewire\Admin;

use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
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
    use InteractsWithNotifications;
    use TogglesSort;
    use WithPagination;

    /**
     * Changing an existing tenant's status is a separate authority from
     * creating one: it takes a live tenant off every entry point.
     */
    public const MANAGE_CAPABILITY = 'admin.tenancy.tenant.manage';

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
            'createStatus' => ['required', 'string', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])],
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

    /**
     * Take a tenant off every entry point.
     *
     * The platform-operator tenant is not special-cased here: `Tenant`'s
     * `saving` invariant refuses that transition for every writer, so this
     * surface only has to avoid *offering* what the model refuses.
     */
    public function suspendTenant(int $tenantId): void
    {
        $this->changeStatus($tenantId, Tenant::STATUS_SUSPENDED);
    }

    /** Return a suspended tenant to service. */
    public function reactivateTenant(int $tenantId): void
    {
        $this->changeStatus($tenantId, Tenant::STATUS_ACTIVE);
    }

    /**
     * The one writer of an existing tenant's status.
     *
     * Linear by design: capability, row, write, audit, feedback. Each call
     * that reaches the write records exactly one action row, and the page
     * only offers the transition a row does not already hold.
     */
    private function changeStatus(int $tenantId, string $status): void
    {
        if (! auth()->user()?->can(self::MANAGE_CAPABILITY)) {
            abort(403);
        }

        $tenant = Tenant::query()->findOrFail($tenantId);
        $previous = (string) $tenant->status;

        // A no-op is not a change. The page never offers the transition a row
        // already holds, but a direct Livewire call can still reach here, and
        // #827 asks for exactly one audit row per change: two suspension
        // events for one suspension is a wrong answer to "when was this
        // tenant suspended?" (reviewer finding, opus-5-extra on #831).
        if ($previous === $status) {
            return;
        }

        $tenant->status = $status;
        $tenant->save();

        $reactivating = $status === Tenant::STATUS_ACTIVE;

        app(SemanticActionRecorder::class)->record(
            event: $reactivating ? 'tenancy.tenant.reactivated' : 'tenancy.tenant.suspended',
            summary: $reactivating
                ? __('Reactivated tenant :name', ['name' => $tenant->name])
                : __('Suspended tenant :name', ['name' => $tenant->name]),
            source: __('Tenants'),
            subject: ['name' => 'tenant', 'id' => (int) $tenant->id, 'identifier' => (string) $tenant->name],
            surface: 'admin.tenancy.tenants',
            uiElement: $reactivating ? __('Reactivate row action') : __('Suspend row action'),
            context: [
                'tenant_id' => (int) $tenant->id,
                'from_status' => $previous,
                'to_status' => $status,
            ],
        );

        $this->notify($reactivating
            ? __('Tenant reactivated.')
            : __('Tenant suspended — its users are signed out and its queued jobs stop running.'));
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
