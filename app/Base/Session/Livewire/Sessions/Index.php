<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Session\Livewire\Sessions;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

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

        $sessions = DB::table('sessions')
            ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
            ->select('sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.name as user_name')
            ->when($this->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('sessions.ip_address', 'like', '%'.$search.'%')
                        ->orWhere('sessions.user_agent', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('sessions.last_activity')
            ->paginate(25);

        return view('livewire.admin.system.sessions.index', [
            'sessions' => $sessions,
            'currentSessionId' => $currentSessionId,
        ]);
    }
}
