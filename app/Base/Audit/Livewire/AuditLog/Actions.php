<?php

namespace App\Base\Audit\Livewire\AuditLog;

use App\Base\Audit\Livewire\AuditLog\Concerns\InteractsWithTraceTimeline;
use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditLogPresenter;
use App\Base\Audit\Services\AuditSearchSql;
use App\Base\Audit\Services\AuditTenantScope;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Actions extends Component
{
    use ChecksCapabilityAuthorization;
    use InteractsWithTraceTimeline;
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $filterActorType = '';

    public string $filterEventFamily = '';

    public string $filterResult = '';

    public string $filterDiagnostics = 'hide';

    /** Platform-operator cross-tenant view (#873). Ignored for non-operators. */
    public bool $allTenants = false;

    public string $sortBy = 'occurred_at';

    public string $sortDir = 'desc';

    private const RESET_PAGE_PROPERTIES = [
        'filterActorType',
        'filterEventFamily',
        'filterResult',
        'filterDiagnostics',
        'allTenants',
    ];

    private const SORTABLE = [
        'occurred_at' => 'base_audit_actions.occurred_at',
        'event' => 'base_audit_actions.event',
        'actor_name' => 'users.name',
        'url' => 'base_audit_actions.url',
        'ip_address' => 'base_audit_actions.ip_address',
        'trace_id' => 'base_audit_actions.trace_id',
    ];

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

    public function updated(string $name): void
    {
        if (in_array($name, self::RESET_PAGE_PROPERTIES, true)) {
            $this->resetPage();
        }
    }

    public function toggleRetain(int $id): void
    {
        if (! $this->checkCapability('admin.audit.log.manage')) {
            return;
        }

        $action = app(AuditTenantScope::class)->apply(
            AuditAction::query(),
            'base_audit_actions',
            $this->allTenants,
        )->findOrFail($id);
        $action->is_retained = ! $action->is_retained;
        $action->save();
    }

    public function render(): View
    {
        $scope = app(AuditTenantScope::class);

        return view('livewire.admin.audit.actions', [
            'actions' => $this->getActions(),
            'actorTypeOptions' => PrincipalType::orderedCases(),
            'presenter' => app(AuditLogPresenter::class),
            'canViewAllTenants' => $scope->ambientIsPlatformOperator(),
            'scopeCaption' => $this->scopeCaption($scope),
        ]);
    }

    private function scopeCaption(AuditTenantScope $scope): string
    {
        return $scope->retentionCaption(
            allTenantsLabel: __('Audit action log (all tenants)'),
            currentTenantLabel: __('Audit action log (current tenant)'),
            allTenants: $this->allTenants,
            retentionDays: (int) config('audit.action_retention_days', 90),
            oldestSource: AuditAction::query(),
            oldestTable: 'base_audit_actions',
            pruneCommandNeedle: 'blb:audit:actions:prune',
        );
    }

    private function getActions(): LengthAwarePaginator
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'base_audit_actions.occurred_at';
        $scope = app(AuditTenantScope::class);
        $searchSql = app(AuditSearchSql::class);

        return $scope->apply(
            $searchSql->withActorName(AuditAction::query(), 'base_audit_actions'),
            'base_audit_actions',
            $this->allTenants,
        )
            ->when($this->search, function ($query, $search) use ($searchSql): void {
                $query->where(function ($q) use ($search, $searchSql): void {
                    $like = '%'.strtolower($search).'%';
                    $trace = app(AuditLogPresenter::class)->normalizeTrace((string) $search);

                    $q->whereRaw('lower(base_audit_actions.event) like ?', [$like])
                        ->orWhereRaw($searchSql->lowerCoalescedLikeExpression('users.name'), [$like])
                        ->orWhereRaw($searchSql->lowerCoalescedLikeExpression('base_audit_actions.actor_role'), [$like])
                        ->orWhereRaw($searchSql->lowerCoalescedLikeExpression('base_audit_actions.url'), [$like])
                        ->orWhereRaw($searchSql->lowerCoalescedLikeExpression($searchSql->ipAddressTextExpression('base_audit_actions.ip_address')), [$like])
                        ->orWhereRaw($searchSql->lowerCoalescedLikeExpression('base_audit_actions.user_agent'), [$like]);

                    if ($trace !== '') {
                        $q->orWhereRaw('base_audit_actions.trace_id like ?', ['%'.$trace.'%']);
                    }

                    $q->orWhereRaw($searchSql->lowerCoalescedLikeExpression($searchSql->jsonTextExpression('base_audit_actions.payload')), [$like]);
                });
            })
            ->when($this->filterActorType, function ($query, $actorType): void {
                $query->where('base_audit_actions.actor_type', $actorType);
            })
            ->when($this->filterEventFamily, function (Builder $query, string $family) use ($searchSql): void {
                $this->applyEventFamilyFilter($query, $family, $searchSql);
            })
            ->when($this->filterResult, function (Builder $query, string $result) use ($searchSql): void {
                $this->applyResultFilter($query, $result, $searchSql);
            })
            ->when($this->filterDiagnostics === 'hide', function (Builder $query) use ($searchSql): void {
                $this->hideSuccessfulDiagnosticRequests($query, $searchSql);
            })
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('base_audit_actions.id')
            ->paginate(25);
    }

    private function applyEventFamilyFilter(Builder $query, string $family, AuditSearchSql $searchSql): void
    {
        match ($family) {
            'http' => $query->where('base_audit_actions.event', 'http.request'),
            'auth' => $query->where('base_audit_actions.event', 'like', 'auth.%'),
            'console' => $query->where('base_audit_actions.event', 'console.command'),
            'queue' => $query->where('base_audit_actions.event', 'like', 'queue.job.%'),
            'product' => $query->whereRaw($searchSql->lowerCoalescedLikeExpression($searchSql->jsonTextExpression('base_audit_actions.payload')), ['%semantic%']),
            'domain' => $query->where('base_audit_actions.event', 'like', 'domain.%'),
            default => null,
        };
    }

    private function applyResultFilter(Builder $query, string $result, AuditSearchSql $searchSql): void
    {
        match ($result) {
            'failure' => $query->where(function (Builder $q) use ($searchSql): void {
                $q->where('base_audit_actions.event', 'auth.login.failed')
                    ->orWhere('base_audit_actions.event', 'queue.job.failed')
                    ->orWhere(function (Builder $http) use ($searchSql): void {
                        $http->where('base_audit_actions.event', 'http.request')
                            ->whereRaw($this->httpStatusExpression($searchSql).' >= 400');
                    })
                    ->orWhere(function (Builder $console) use ($searchSql): void {
                        $console->where('base_audit_actions.event', 'console.command')
                            ->whereRaw('coalesce('.$searchSql->jsonIntegerExpression('base_audit_actions.payload', 'exit_code').', 0) <> 0');
                    })
                    ->orWhere(function (Builder $domain) use ($searchSql): void {
                        $domain->where('base_audit_actions.event', 'like', 'domain.%')
                            ->whereRaw($searchSql->lowerCoalescedLikeExpression($searchSql->jsonTextExpression('base_audit_actions.payload')), ['%failed%']);
                    })
                    ->orWhere(function (Builder $semantic) use ($searchSql): void {
                        $payloadTextExpression = $searchSql->jsonTextExpression('base_audit_actions.payload');

                        $semantic->whereRaw($searchSql->lowerCoalescedLikeExpression($payloadTextExpression), ['%semantic%'])
                            ->whereRaw($searchSql->lowerCoalescedLikeExpression($payloadTextExpression), ['%failed%']);
                    });
            }),
            'retained' => $query->where('base_audit_actions.is_retained', true),
            default => null,
        };
    }

    private function hideSuccessfulDiagnosticRequests(Builder $query, AuditSearchSql $searchSql): void
    {
        $query->where(function (Builder $outer) use ($searchSql): void {
            $outer->where('base_audit_actions.event', '<>', 'http.request')
                ->orWhere(function (Builder $http) use ($searchSql): void {
                    $http->where('base_audit_actions.event', 'http.request')
                        ->where(function (Builder $visible) use ($searchSql): void {
                            $visible->whereRaw($this->httpStatusExpression($searchSql).' >= 400')
                                ->orWhere(function (Builder $normal) use ($searchSql): void {
                                    $this->whereNotPayloadLike($normal, $searchSql, 'default-livewire.update');
                                    $this->whereNotPayloadLike($normal, $searchSql, 'ai.chat.turn.events');
                                    $this->whereNotPayloadLike($normal, $searchSql, 'media.assets.stream');
                                    $this->whereNotUrlLike($normal, $searchSql, '/livewire');
                                    $this->whereNotUrlLike($normal, $searchSql, '/api/ai/chat/turns/');
                                    $this->whereNotUrlLike($normal, $searchSql, '/media/assets/');
                                });
                        });
                });
        });
    }

    private function whereNotPayloadLike(Builder $query, AuditSearchSql $searchSql, string $needle): void
    {
        $query->whereRaw($searchSql->lowerCoalescedExpression($searchSql->jsonTextExpression('base_audit_actions.payload')).' not like ?', ['%'.strtolower($needle).'%']);
    }

    private function whereNotUrlLike(Builder $query, AuditSearchSql $searchSql, string $needle): void
    {
        $query->whereRaw($searchSql->lowerCoalescedExpression('base_audit_actions.url').' not like ?', ['%'.strtolower($needle).'%']);
    }

    private function httpStatusExpression(AuditSearchSql $searchSql): string
    {
        return 'coalesce('.$searchSql->jsonIntegerExpression('base_audit_actions.payload', 'status').', 0)';
    }
}
