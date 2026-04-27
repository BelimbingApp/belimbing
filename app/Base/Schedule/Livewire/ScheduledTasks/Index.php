<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Schedule\Livewire\ScheduledTasks;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    /**
     * Clean the artisan command string for display.
     *
     * @param  string  $command  Full command string including php/artisan prefix
     */
    public function cleanCommand(string $command): string
    {
        $command = preg_replace('/^.*artisan\s+/', '', $command);

        return trim($command, "'\"");
    }

    public function render(): View
    {
        $schedule = app(Schedule::class);
        $events = $schedule->events();

        return view('livewire.admin.system.scheduled-tasks.index', [
            'events' => $events,
            'totalCount' => count($events),
        ]);
    }
}
