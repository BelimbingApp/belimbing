<?php

namespace App\Base\Schedule\Livewire\Concerns;

trait ProvidesScheduleStatusOptions
{
    /**
     * @return array<string, string>
     */
    private function taskStatusOptions(): array
    {
        return [
            'all' => __('All task statuses'),
            'never' => __('Never run'),
            'queued' => __('Queued'),
            'running' => __('Running'),
            'succeeded' => __('Succeeded'),
            'failed' => __('Failed'),
            'cancelled' => __('Cancelled'),
            'skipped' => __('Skipped'),
            'paused' => __('Paused'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function historyStatusOptions(): array
    {
        return [
            'all' => __('All run statuses'),
            'queued' => __('Queued'),
            'running' => __('Running'),
            'succeeded' => __('Succeeded'),
            'failed' => __('Failed'),
            'cancelled' => __('Cancelled'),
            'skipped' => __('Skipped'),
        ];
    }
}
