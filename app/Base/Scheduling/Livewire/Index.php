<?php

namespace App\Base\Scheduling\Livewire;

use App\Base\Scheduling\Services\SchedulingBoard;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Central scheduling observability: everything scheduled to fire (Laravel
 * scheduler + contributor sources), soonest first, and the merged run
 * history. Read-only by design - schedules are owned and edited where they
 * live (module pages, code); this page answers what/when/how it went.
 */
class Index extends Component
{
    public function render(SchedulingBoard $board): View
    {
        return view('livewire.admin.system.scheduling.index', [
            'upcoming' => $board->upcoming(),
            'runs' => $board->recentRuns(50),
        ]);
    }
}
