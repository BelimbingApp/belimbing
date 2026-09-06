<?php

namespace App\Base\Audit\Livewire\AuditLog;

use App\Base\Audit\Models\AuditAction;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator-facing audit activity: actor, operation, and when — never the
 * payload column. Scoped to the current tenant (#650 / plan 0001).
 */
class OperatorActivity extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $filterOperation = '';

    public string $filterActor = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    public string $sortBy = 'occurred_at';

    public string $sortDir = 'desc';

    private const SORTABLE = [
        'occurred_at' => 'base_audit_actions.occurred_at',
        'actor_name' => 'users.name',
        'event' => 'base_audit_actions.event',
    ];

    /** Columns safe to expose; payload is intentionally omitted. */
    private const SAFE_COLUMNS = [
        'base_audit_actions.id',
        'base_audit_actions.tenant_id',
        'base_audit_actions.company_id',
        'base_audit_actions.actor_type',
        'base_audit_actions.actor_id',
        'base_audit_actions.actor_role',
        'base_audit_actions.event',
        'base_audit_actions.url',
        'base_audit_actions.occurred_at',
    ];

    public function mount(): void
    {
        $actor = Actor::forUser(auth()->user());
        app(AuthorizationService::class)->authorize($actor, 'admin.audit.log.list');
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'occurred_at' => 'desc',
            ],
        );
    }

    public function updatedFilterOperation(): void
    {
        $this->resetPage();
    }

    public function updatedFilterActor(): void
    {
        $this->resetPage();
    }

    public function updatedFilterFrom(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTo(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.audit.operator-activity', [
            'rows' => $this->rows(),
        ]);
    }

    private function rows(): LengthAwarePaginator
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'base_audit_actions.occurred_at';

        return AuditAction::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_audit_actions.actor_id', '=', 'users.id')
                    ->where('base_audit_actions.actor_type', '=', PrincipalType::USER->value);
            })
            ->select([...self::SAFE_COLUMNS, 'users.name as actor_name'])
            // Guard: cross-tenant rows must never surface. Deleting this where
            // clause makes the companion Pest denial fail.
            ->where('base_audit_actions.tenant_id', $tenantId)
            ->when($this->search !== '', function (Builder $query): void {
                $like = '%'.strtolower($this->search).'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->whereRaw('lower(base_audit_actions.event) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(users.name, \'\')) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(base_audit_actions.actor_role, \'\')) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(base_audit_actions.url, \'\')) like ?', [$like]);
                });
            })
            ->when($this->filterOperation !== '', function (Builder $query): void {
                $query->where('base_audit_actions.event', $this->filterOperation);
            })
            ->when($this->filterActor !== '', function (Builder $query): void {
                $like = '%'.strtolower($this->filterActor).'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->whereRaw('lower(coalesce(users.name, \'\')) like ?', [$like]);
                    if (ctype_digit($this->filterActor)) {
                        $inner->orWhere('base_audit_actions.actor_id', (int) $this->filterActor);
                    }
                });
            })
            ->when($this->filterFrom !== '', function (Builder $query): void {
                $query->whereDate('base_audit_actions.occurred_at', '>=', $this->filterFrom);
            })
            ->when($this->filterTo !== '', function (Builder $query): void {
                $query->whereDate('base_audit_actions.occurred_at', '<=', $this->filterTo);
            })
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('base_audit_actions.id')
            ->paginate(25);
    }
}
