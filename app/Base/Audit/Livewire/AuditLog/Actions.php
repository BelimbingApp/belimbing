<?php

namespace App\Base\Audit\Livewire\AuditLog;

use App\Base\Audit\Livewire\AuditLog\Concerns\InteractsWithTraceTimeline;
use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditLogPresenter;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Actions extends Component
{
    use InteractsWithTraceTimeline;
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $filterActorType = '';

    public string $filterEventFamily = '';

    public string $filterResult = '';

    public string $filterDiagnostics = 'hide';

    public string $sortBy = 'occurred_at';

    public string $sortDir = 'desc';

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

    public function updatedFilterActorType(): void
    {
        $this->resetPage();
    }

    public function updatedFilterEventFamily(): void
    {
        $this->resetPage();
    }

    public function updatedFilterResult(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDiagnostics(): void
    {
        $this->resetPage();
    }

    public function toggleRetain(int $id): void
    {
        $action = AuditAction::query()->findOrFail($id);
        $action->is_retained = ! $action->is_retained;
        $action->save();
    }

    public function render(): View
    {
        return view('livewire.admin.audit.actions', [
            'actions' => $this->getActions(),
            'actorTypeOptions' => PrincipalType::orderedCases(),
            'presenter' => app(AuditLogPresenter::class),
        ]);
    }

    private function getActions(): LengthAwarePaginator
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'base_audit_actions.occurred_at';

        return AuditAction::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_audit_actions.actor_id', '=', 'users.id')
                    ->where('base_audit_actions.actor_type', '=', PrincipalType::USER->value);
            })
            ->select('base_audit_actions.*', 'users.name as actor_name')
            ->when($this->search, function ($query, $search): void {
                $query->where(function ($q) use ($search): void {
                    $like = '%'.strtolower($search).'%';
                    $trace = app(AuditLogPresenter::class)->normalizeTrace((string) $search);

                    $q->whereRaw('lower(base_audit_actions.event) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(users.name, \'\')) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(base_audit_actions.actor_role, \'\')) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(base_audit_actions.url, \'\')) like ?', [$like])
                        ->orWhereRaw('lower(coalesce('.$this->ipAddressTextExpression().', \'\')) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(base_audit_actions.user_agent, \'\')) like ?', [$like]);

                    if ($trace !== '') {
                        $q->orWhereRaw('base_audit_actions.trace_id like ?', ['%'.$trace.'%']);
                    }

                    $q->orWhereRaw('lower(coalesce('.$this->payloadTextExpression().', \'\')) like ?', [$like]);
                });
            })
            ->when($this->filterActorType, function ($query, $actorType): void {
                $query->where('base_audit_actions.actor_type', $actorType);
            })
            ->when($this->filterEventFamily, function (Builder $query, string $family): void {
                $this->applyEventFamilyFilter($query, $family);
            })
            ->when($this->filterResult, function (Builder $query, string $result): void {
                $this->applyResultFilter($query, $result);
            })
            ->when($this->filterDiagnostics === 'hide', function (Builder $query): void {
                $this->hideSuccessfulDiagnosticRequests($query);
            })
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('base_audit_actions.id')
            ->paginate(25);
    }

    private function applyEventFamilyFilter(Builder $query, string $family): void
    {
        match ($family) {
            'http' => $query->where('base_audit_actions.event', 'http.request'),
            'auth' => $query->where('base_audit_actions.event', 'like', 'auth.%'),
            'console' => $query->where('base_audit_actions.event', 'console.command'),
            'queue' => $query->where('base_audit_actions.event', 'like', 'queue.job.%'),
            'product' => $query->whereRaw('lower(coalesce('.$this->payloadTextExpression().', \'\')) like ?', ['%semantic%']),
            'domain' => $query->where('base_audit_actions.event', 'like', 'domain.%'),
            default => null,
        };
    }

    private function applyResultFilter(Builder $query, string $result): void
    {
        match ($result) {
            'failure' => $query->where(function (Builder $q): void {
                $q->where('base_audit_actions.event', 'auth.login.failed')
                    ->orWhere('base_audit_actions.event', 'queue.job.failed')
                    ->orWhere(function (Builder $http): void {
                        $http->where('base_audit_actions.event', 'http.request')
                            ->whereRaw($this->httpStatusExpression().' >= 400');
                    })
                    ->orWhere(function (Builder $console): void {
                        $console->where('base_audit_actions.event', 'console.command')
                            ->whereRaw('coalesce('.$this->payloadIntegerExpression('exit_code').', 0) <> 0');
                    })
                    ->orWhere(function (Builder $domain): void {
                        $domain->where('base_audit_actions.event', 'like', 'domain.%')
                            ->whereRaw('lower(coalesce('.$this->payloadTextExpression().', \'\')) like ?', ['%failed%']);
                    })
                    ->orWhere(function (Builder $semantic): void {
                        $semantic->whereRaw('lower(coalesce('.$this->payloadTextExpression().', \'\')) like ?', ['%semantic%'])
                            ->whereRaw('lower(coalesce('.$this->payloadTextExpression().', \'\')) like ?', ['%failed%']);
                    });
            }),
            'retained' => $query->where('base_audit_actions.is_retained', true),
            default => null,
        };
    }

    private function hideSuccessfulDiagnosticRequests(Builder $query): void
    {
        $query->where(function (Builder $outer): void {
            $outer->where('base_audit_actions.event', '<>', 'http.request')
                ->orWhere(function (Builder $http): void {
                    $http->where('base_audit_actions.event', 'http.request')
                        ->where(function (Builder $visible): void {
                            $visible->whereRaw($this->httpStatusExpression().' >= 400')
                                ->orWhere(function (Builder $normal): void {
                                    $this->whereNotPayloadLike($normal, 'default-livewire.update');
                                    $this->whereNotPayloadLike($normal, 'ai.chat.turn.events');
                                    $this->whereNotPayloadLike($normal, 'media.assets.stream');
                                    $this->whereNotUrlLike($normal, '/livewire');
                                    $this->whereNotUrlLike($normal, '/api/ai/chat/turns/');
                                    $this->whereNotUrlLike($normal, '/media/assets/');
                                });
                        });
                });
        });
    }

    private function whereNotPayloadLike(Builder $query, string $needle): void
    {
        $query->whereRaw('lower(coalesce('.$this->payloadTextExpression().', \'\')) not like ?', ['%'.strtolower($needle).'%']);
    }

    private function whereNotUrlLike(Builder $query, string $needle): void
    {
        $query->whereRaw('lower(coalesce(base_audit_actions.url, \'\')) not like ?', ['%'.strtolower($needle).'%']);
    }

    private function payloadTextExpression(): string
    {
        return match (config('database.default')) {
            'pgsql' => 'base_audit_actions.payload::text',
            'mysql', 'mariadb' => 'cast(base_audit_actions.payload as char)',
            default => 'base_audit_actions.payload',
        };
    }

    private function ipAddressTextExpression(): string
    {
        return match (config('database.default')) {
            'mysql', 'mariadb' => 'cast(base_audit_actions.ip_address as char)',
            default => 'cast(base_audit_actions.ip_address as text)',
        };
    }

    private function httpStatusExpression(): string
    {
        return 'coalesce('.$this->payloadIntegerExpression('status').', 0)';
    }

    private function payloadIntegerExpression(string $key): string
    {
        return match (config('database.default')) {
            'pgsql' => "nullif(base_audit_actions.payload->>'{$key}', '')::int",
            'mysql', 'mariadb' => "cast(json_unquote(json_extract(base_audit_actions.payload, '$.{$key}')) as signed)",
            default => "cast(json_extract(base_audit_actions.payload, '$.{$key}') as integer)",
        };
    }
}
