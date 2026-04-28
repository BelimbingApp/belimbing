<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Session\Livewire\Sessions;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

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
        if ($sessionId === session()->getId()) {
            return;
        }

        DB::table('sessions')->where('id', $sessionId)->delete();
    }

    public function render(): View
    {
        $currentSessionId = session()->getId();

        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'sessions.last_activity';

        $sessions = DB::table('sessions')
            ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
            ->select('sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.name as user_name')
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
        ]);
    }
}
