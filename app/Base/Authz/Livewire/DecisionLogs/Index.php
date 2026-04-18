<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\DecisionLogs;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\DecisionLog;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public string $filterResult = '';

    public function updatedFilterResult(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.authz.decision-logs.index', [
            'logs' => DecisionLog::query()
                ->leftJoin('users', function ($join): void {
                    $join->on('base_authz_decision_logs.actor_id', '=', 'users.id')
                        ->where('base_authz_decision_logs.actor_type', '=', PrincipalType::USER->value);
                })
                ->select('base_authz_decision_logs.*', 'users.name as actor_name')
                ->when($this->search, function ($query, $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('capability', 'like', '%'.$search.'%')
                            ->orWhere('reason_code', 'like', '%'.$search.'%')
                            ->orWhere('users.name', 'like', '%'.$search.'%')
                            ->orWhere('resource_type', 'like', '%'.$search.'%');
                    });
                })
                ->when($this->filterResult === 'allowed', function ($query): void {
                    $query->where('base_authz_decision_logs.allowed', true);
                })
                ->when($this->filterResult === 'denied', function ($query): void {
                    $query->where('base_authz_decision_logs.allowed', false);
                })
                ->orderByDesc('base_authz_decision_logs.occurred_at')
                ->paginate(25),
        ]);
    }
}
