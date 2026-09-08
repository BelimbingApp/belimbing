<?php

namespace App\Base\Session\Livewire\Sessions;

use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ChecksCapabilityAuthorization;
    use InteractsWithNotifications;
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    /** Platform-operator cross-tenant view (#873). Ignored for non-operators. */
    public bool $allTenants = false;

    public string $sortBy = 'last_activity';

    public string $sortDir = 'desc';

    private const SORTABLE = [
        'user_name' => 'users.name',
        'ip_address' => 'sessions.ip_address',
        'user_agent' => 'sessions.user_agent',
        'last_activity' => 'sessions.last_activity',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'user_name' => 'asc',
                'ip_address' => 'asc',
                'user_agent' => 'asc',
                'last_activity' => 'desc',
            ],
        );
    }

    public function terminate(string $sessionId): void
    {
        if (! $this->checkCapability('admin.system.session.manage')) {
            return;
        }

        if ($sessionId === session()->getId()) {
            return;
        }

        // Resolved through the same query that draws the table, so a row this
        // admin cannot see is a row they cannot end. A separate predicate here
        // would be a second rule to keep in step with the first, and the id
        // arrives over the wire from a client that can send any string.
        // Named columns, not `select *`: the join reaches three tables that each
        // have `id` and two that have `name`, so an unqualified row would carry
        // a company's name under `name` and no `user_name` at all.
        $session = $this->visibleSessions()
            ->select('sessions.id', 'sessions.user_id', 'users.name as user_name')
            ->where('sessions.id', $sessionId)
            ->first();

        if ($session === null) {
            $this->notifyError(__('That session does not belong to this tenant.'));

            return;
        }

        DB::table('sessions')->where('id', $sessionId)->delete();

        // The session id is a live credential: a prefix of it is still part of
        // one. The audit row carries a hash prefix instead, which is enough to
        // tie two rows to the same session and useless to anyone replaying it.
        app(SemanticActionRecorder::class)->record(
            event: 'session.terminated',
            summary: __('Terminated a session for :user', [
                'user' => $session->user_name ?? __('Guest'),
            ]),
            source: __('Sessions'),
            subject: [
                'name' => 'session',
                'id' => substr(hash('sha256', $sessionId), 0, 12),
                'identifier' => $session->user_name,
            ],
            surface: 'admin.system.sessions',
            uiElement: __('Terminate row action'),
            context: [
                'user_id' => $session->user_id === null ? null : (int) $session->user_id,
            ],
        );
    }

    public function render(): View
    {
        $currentSessionId = session()->getId();

        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'sessions.last_activity';

        $sessions = $this->visibleSessions()
            ->select(
                'sessions.id',
                'sessions.user_id',
                'sessions.ip_address',
                'sessions.user_agent',
                'sessions.last_activity',
                'users.name as user_name',
            )
            ->when($this->search, function ($query, $search) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('sessions.ip_address', 'like', '%'.$search.'%')
                        ->orWhere('sessions.user_agent', 'like', '%'.$search.'%')
                        ->orWhere('users.name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('sessions.id')
            ->paginate(25);

        return view('livewire.admin.system.sessions.index', [
            'sessions' => $sessions,
            'currentSessionId' => $currentSessionId,
            'canViewAllTenants' => $this->ambientIsPlatformOperator(),
            'scopeCaption' => $this->crossTenantView()
                ? __('Active sessions (all tenants)')
                : __('Active sessions (current tenant)'),
        ]);
    }

    /**
     * The sessions this admin may see: their own tenant's, found through the
     * signed-in user's company.
     *
     * A session row itself names no tenant, so the boundary is the user behind
     * it. That also settles guest sessions, which name no user either: they
     * have no tenant to belong to, so only the platform operator asking for the
     * cross-tenant view ever sees one.
     */
    private function visibleSessions(): Builder
    {
        $query = DB::table('sessions')
            ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
            ->leftJoin('companies', 'users.company_id', '=', 'companies.id');

        if ($this->crossTenantView()) {
            return $query;
        }

        return $query->where('companies.tenant_id', app(TenantContext::class)->requireTenantId());
    }

    /**
     * `allTenants` is a public property, so a client can set it over the wire.
     * It only means anything once the ambient tenant is checked here.
     */
    private function crossTenantView(): bool
    {
        return $this->allTenants && $this->ambientIsPlatformOperator();
    }

    private function ambientIsPlatformOperator(): bool
    {
        $tenantId = app(TenantContext::class)->currentTenantId();

        if ($tenantId === null) {
            return false;
        }

        return Tenant::query()->find($tenantId)?->isPlatformOperator() === true;
    }
}
