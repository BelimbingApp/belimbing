<?php

namespace App\Base\Scheduling\Livewire;

use App\Base\Scheduling\Models\ScheduleSuppression;
use App\Base\Scheduling\Services\SchedulingBoard;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Central scheduling observability: everything scheduled to fire (Laravel
 * scheduler + contributor sources), soonest first, and the merged run
 * history. Editing stays where schedules are owned (module pages, code);
 * the one operation here is pausing/resuming scheduler entries, enforced by
 * the ServiceProvider's skip hook at tick time.
 */
class Index extends Component
{
    public function pause(string $name): void
    {
        ScheduleSuppression::query()->firstOrCreate(['name' => $name]);
    }

    public function resume(string $name): void
    {
        ScheduleSuppression::query()->where('name', $name)->delete();
    }

    public function render(SchedulingBoard $board): View
    {
        return view('livewire.admin.system.scheduling.index', [
            'upcoming' => $board->upcoming(),
            'runs' => $board->recentRuns(50),
        ]);
    }
}
