<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Schedule\Livewire\ScheduledTasks;

use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    use TogglesSort;

    public string $sortBy = 'command';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'command' => true,
        'expression' => true,
        'description' => true,
        'timezone' => true,
        'flags' => true,
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'command' => 'asc',
                'expression' => 'asc',
                'description' => 'asc',
                'timezone' => 'asc',
                'flags' => 'asc',
            ],
            resetPage: false,
        );
    }

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
        $events = collect($schedule->events())
            ->sort(fn (Event $a, Event $b): int => $this->compareScheduledEvents($a, $b))
            ->values()
            ->all();

        return view('livewire.admin.system.scheduled-tasks.index', [
            'events' => $events,
            'totalCount' => count($events),
        ]);
    }

    private function compareScheduledEvents(Event $a, Event $b): int
    {
        $dir = $this->sortDir === 'desc' ? -1 : 1;

        $flags = fn (Event $event): string => implode('|', array_filter([
            $event->withoutOverlapping ? 'withoutOverlapping' : null,
            $event->onOneServer ? 'onOneServer' : null,
            $event->runInBackground ? 'runInBackground' : null,
        ]));

        $primary = match ($this->sortBy) {
            'command' => $dir * strcmp($this->cleanCommand($a->command), $this->cleanCommand($b->command)),
            'expression' => $dir * strcmp((string) $a->expression, (string) $b->expression),
            'description' => $dir * strcmp((string) ($a->description ?? ''), (string) ($b->description ?? '')),
            'timezone' => $dir * strcmp((string) ($a->timezone ?? ''), (string) ($b->timezone ?? '')),
            'flags' => $dir * strcmp($flags($a), $flags($b)),
            default => $dir * strcmp($this->cleanCommand($a->command), $this->cleanCommand($b->command)),
        };

        if ($primary !== 0) {
            return $primary;
        }

        return strcmp((string) $a->expression, (string) $b->expression);
    }
}
