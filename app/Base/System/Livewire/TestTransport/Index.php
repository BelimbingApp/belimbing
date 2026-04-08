<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Livewire\TestTransport;

use App\Modules\Core\AI\Models\ChatTurn;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public string $selectedTurnId = '';

    public int $speed = 1;

    public function render(): View
    {
        $turns = ChatTurn::query()
            ->withCount('events')
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'session_id', 'status', 'current_phase', 'started_at', 'completed_at', 'created_at']);

        return view('livewire.admin.system.test-transport.index', [
            'turns' => $turns,
            'streamUrl' => route('admin.system.test-transport.stream'),
        ]);
    }
}
