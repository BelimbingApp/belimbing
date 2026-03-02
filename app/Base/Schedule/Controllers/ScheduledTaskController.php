<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Schedule\Controllers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\View\View;

class ScheduledTaskController
{
    /**
     * Show scheduled tasks.
     */
    public function index(): View
    {
        $events = app(Schedule::class)->events();
        $totalCount = count($events);

        return view('admin.system.scheduled-tasks.index', compact('events', 'totalCount'));
    }

    /**
     * Clean command string for display.
     */
    public static function cleanCommand(string $command): string
    {
        $cleaned = preg_replace('/^.*artisan\s+/', '', $command);

        return trim((string) $cleaned, "'\"");
    }
}
