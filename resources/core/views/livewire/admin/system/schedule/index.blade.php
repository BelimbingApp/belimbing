@php /** @var \App\Base\Schedule\Livewire\Index $this */ @endphp
<?php

use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleTask;
use Illuminate\Pagination\LengthAwarePaginator;

/** @var list<ScheduleTask> $tasks */
/** @var LengthAwarePaginator<int, RecordedRun> $runs */
/** @var string $taskEmptyMessage */
/** @var string $historyEmptyMessage */
/** @var ?string $historyRangeError */
/** @var array<string, string|null> $cronDescriptions */
/** @var array<string, string> $periodOptions */
/** @var array<string, string> $taskStatusOptions */
/** @var array<string, string> $historyStatusOptions */
/** @var bool $canExecute */
/** @var bool $canManage */
$statusVariant = fn (?string $status): string => match ($status) {
    'succeeded' => 'success',
    'running' => 'info',
    'failed' => 'danger',
    'cancelled', 'skipped' => 'warning',
    default => 'default',
};
$statusLabel = fn (?string $status): string => match ($status) {
    null, '' => __('Never'),
    'succeeded' => __('Succeeded'),
    'running' => __('Running'),
    'queued' => __('Queued'),
    'failed' => __('Failed'),
    'cancelled' => __('Cancelled'),
    'skipped' => __('Skipped'),
    default => str($status)->replace(['-', '_'], ' ')->headline()->toString(),
};
$duration = function ($start, $end): string {
    if ($start === null || $end === null) {
        return '—';
    }
    $seconds = max(1, (int) $start->diffInSeconds($end));

    return $seconds >= 90 ? intdiv($seconds, 60).'m '.($seconds % 60).'s' : $seconds.'s';
};
$tabs = [
    ['id' => 'tasks', 'label' => __('Tasks'), 'icon' => 'heroicon-o-clock'],
    ['id' => 'history', 'label' => __('History'), 'icon' => 'heroicon-o-queue-list'],
    ['id' => 'settings', 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth'],
];
?>

<div wire:poll.visible.5s>
    <x-slot name="title">{{ __('Schedule') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Schedule')"
            :subtitle="__('Everything scheduled to fire across the system, and how the last runs went.')"
        />

        <x-ui.tabs :tabs="$tabs" :default="$tab" persistence="query" query-key="tab" wire-action="setTab">
            <x-ui.tab id="tasks">
                <x-ui.card>
                    <div class="mb-3 flex flex-col gap-3 sm:flex-row">
                        <div class="min-w-0 flex-1">
                            <label class="sr-only" for="schedule-task-search">{{ __('Search task name') }}</label>
                            <x-ui.search-input
                                id="schedule-task-search"
                                wire:model.live.debounce.300ms="taskSearch"
                                placeholder="{{ __('Search task name…') }}"
                            />
                        </div>
                        <div class="sm:w-64">
                            <label class="sr-only" for="schedule-task-status">{{ __('Task status') }}</label>
                            <x-ui.select id="schedule-task-status" wire:model.live="taskStatus">
                                @foreach($taskStatusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    </div>

                    <x-ui.table container="flush" :caption="__('Schedule tasks')" :empty="$tasks === []" :empty-colspan="7" :empty-message="$taskEmptyMessage">
                        <x-slot:head>
                            <tr>
                                <x-ui.sortable-th column="name" :sort-by="$taskSort" :sort-dir="$taskSortDirection" method="sortTasks" :label="__('Name')" />
                                <x-ui.th>{{ __('Source') }}</x-ui.th>
                                <x-ui.th
                                    nowrap
                                    title="{{ __('Cron is read-only on this board. Module-owned schedules link to their owning page when editable; framework scheduler entries are changed in code.') }}"
                                >
                                    {{ __('Cron schedule') }}
                                </x-ui.th>
                                <x-ui.sortable-th column="next_run" :sort-by="$taskSort" :sort-dir="$taskSortDirection" method="sortTasks" :label="__('Next run (countdown)')" nowrap />
                                <x-ui.th>{{ __('Status') }}</x-ui.th>
                                <x-ui.sortable-th column="last_run" :sort-by="$taskSort" :sort-dir="$taskSortDirection" method="sortTasks" :label="__('Last run')" nowrap />
                                <x-ui.th>{{ __('Result') }}</x-ui.th>
                            </tr>
                        </x-slot:head>
                        <x-slot:body>
                            @foreach($tasks as $item)
                                @php($cronDescription = $cronDescriptions[$item->source.'|'.$item->key] ?? null)
                                <tr wire:key="task-{{ $item->source }}-{{ md5($item->key) }}">
                                    <td class="px-table-cell-x py-table-cell-y font-mono text-sm text-ink">
                                        <div class="flex min-w-0 items-center gap-2">
                                            @if($item->url)
                                                <a class="min-w-0 truncate text-accent hover:underline" href="{{ $item->url }}">{{ $item->name }}</a>
                                            @else
                                                <span class="min-w-0 truncate">{{ $item->name }}</span>
                                            @endif
                                            @if($item->paused)
                                                <x-ui.badge variant="warning">{{ __('Paused') }}</x-ui.badge>
                                            @endif

                                            @if($item->source === 'scheduler')
                                                <x-ui.icon-action-group class="shrink-0">
                                                    @if($canExecute && ! $item->paused)
                                                        <x-ui.icon-action
                                                            icon="heroicon-o-play"
                                                            :label="__('Run :task now', ['task' => $item->name])"
                                                            :title="__('Run now')"
                                                            wire:click="runNow(@js($item->key))"
                                                            wire:loading.attr="disabled"
                                                            wire:target="runNow"
                                                        />
                                                    @endif

                                                    @if($canManage && $item->canPause)
                                                        @if($item->paused)
                                                            <x-ui.icon-action
                                                                icon="heroicon-o-arrow-path"
                                                                :label="__('Resume :task', ['task' => $item->name])"
                                                                :title="__('Resume')"
                                                                wire:click="resume(@js($item->key))"
                                                                wire:loading.attr="disabled"
                                                                wire:target="resume"
                                                            />
                                                        @else
                                                            <x-ui.icon-action
                                                                icon="heroicon-o-pause"
                                                                :label="__('Pause :task', ['task' => $item->name])"
                                                                :title="__('Pause')"
                                                                wire:click="pause(@js($item->key), @js($item->name))"
                                                                wire:loading.attr="disabled"
                                                                wire:target="pause"
                                                            />
                                                        @endif
                                                    @endif
                                                </x-ui.icon-action-group>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm">
                                        <x-ui.badge variant="{{ $item->source === 'scheduler' ? 'default' : 'info' }}">{{ $item->source }}</x-ui.badge>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y min-w-40 text-sm">
                                        <span
                                            class="inline-flex items-center gap-1 font-mono text-muted"
                                            @if($cronDescription) title="{{ $cronDescription }}" @endif
                                        >
                                            {{ $item->cron }}
                                        </span>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">
                                        @if($item->paused)
                                            <span class="text-muted">—</span>
                                        @elseif($item->nextRunAt !== null)
                                            <x-ui.datetime :value="$item->nextRunAt" />
                                            <span class="text-xs text-muted">({{ $item->nextRunAt->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }})</span>
                                        @else
                                            <span class="text-muted">{{ __('Disabled') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm">
                                        <x-ui.badge variant="{{ $statusVariant($item->status) }}">{{ $statusLabel($item->status) }}</x-ui.badge>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                        @if($item->lastRunAt !== null)
                                            <x-ui.datetime :value="$item->lastRunAt" />
                                            <span class="text-xs text-muted">({{ $duration($item->lastRunAt, $item->lastFinishedAt) }})</span>
                                        @else
                                            {{ __('Never') }}
                                        @endif
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y max-w-80 truncate font-mono text-xs text-muted" @if($item->lastResult) title="{{ mb_substr($item->lastResult, 0, 700) }}" @endif>
                                        {{ $item->lastResult ? str()->limit($item->lastResult, 90) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </x-slot:body>
                    </x-ui.table>
                </x-ui.card>
            </x-ui.tab>

            <x-ui.tab id="history">
                <x-ui.card>
                    <div class="mb-3 flex flex-col gap-3 lg:flex-row">
                        <div class="min-w-0 flex-1">
                            <label class="sr-only" for="schedule-history-search">{{ __('Search run name') }}</label>
                            <x-ui.search-input
                                id="schedule-history-search"
                                wire:model.live.debounce.300ms="historySearch"
                                placeholder="{{ __('Search run name…') }}"
                            />
                        </div>
                        <div class="lg:w-56">
                            <label class="sr-only" for="schedule-history-status">{{ __('Run status') }}</label>
                            <x-ui.select id="schedule-history-status" wire:model.live="historyStatus">
                                @foreach($historyStatusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                        <x-ui.period-filter
                            id-prefix="schedule-history"
                            :period="$period"
                            :period-options="$periodOptions"
                            :from="$from"
                            :to="$to"
                            :range-error="$periodDraftError"
                            :period-label="__('Period')"
                            :from-label="__('Start date')"
                            :to-label="__('End date')"
                            variant="toolbar"
                        />
                    </div>

                    @if($historyRangeError)
                        <x-ui.alert variant="danger" class="mb-3">{{ $historyRangeError }}</x-ui.alert>
                    @endif

                    <x-ui.table container="flush" :caption="__('Schedule history')" :empty="$runs->count() === 0" :empty-colspan="5" :empty-message="$historyEmptyMessage">
                        <x-slot:head>
                            <tr>
                                <x-ui.sortable-th column="started_at" :sort-by="$historySort" :sort-dir="$historySortDirection" method="sortHistory" :label="__('Started (Duration)')" nowrap />
                                <x-ui.sortable-th column="name" :sort-by="$historySort" :sort-dir="$historySortDirection" method="sortHistory" :label="__('Name')" />
                                <x-ui.sortable-th column="source" :sort-by="$historySort" :sort-dir="$historySortDirection" method="sortHistory" :label="__('Source')" />
                                <x-ui.sortable-th column="status" :sort-by="$historySort" :sort-dir="$historySortDirection" method="sortHistory" :label="__('Status')" />
                                <x-ui.th>{{ __('Detail') }}</x-ui.th>
                            </tr>
                        </x-slot:head>
                        <x-slot:body>
                            @foreach($runs as $run)
                                <tr wire:key="run-{{ md5($run->source.$run->name.$run->startedAt->timestamp) }}-{{ $loop->index }}" @if($run->detail) title="{{ mb_substr($run->detail, 0, 700) }}" @endif>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                        <x-ui.datetime :value="$run->startedAt" />
                                        <span class="text-xs text-muted">({{ $duration($run->startedAt, $run->finishedAt) }})</span>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y font-mono text-sm text-ink">{{ $run->name }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm"><x-ui.badge variant="{{ $run->source === 'scheduler' ? 'default' : 'info' }}">{{ $run->source }}</x-ui.badge></td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm"><x-ui.badge variant="{{ $statusVariant($run->status) }}">{{ $statusLabel($run->status) }}</x-ui.badge></td>
                                    <td class="px-table-cell-x py-table-cell-y max-w-md truncate font-mono text-xs text-muted">{{ $run->detail ? str()->limit($run->detail, 90) : '—' }}</td>
                                </tr>
                            @endforeach
                        </x-slot:body>
                    </x-ui.table>

                    @if($runs->total() > 0)
                        <div class="mt-3">
                            <x-ui.pagination
                                :paginator="$runs"
                                :per-page-options="$this->perPageOptions()"
                                :per-page="$perPage"
                                id="schedule-history-per-page"
                            />
                        </div>
                    @endif
                </x-ui.card>
            </x-ui.tab>

            <x-ui.tab id="settings">
                <x-ui.card>
                    @if($canManage)
                        <div class="max-w-xl">
                            <x-ui.edit-in-place.text
                                id="schedule-history-keep-days"
                                field="keepDays"
                                save-method="saveField"
                                type="number"
                                inputmode="numeric"
                                min="0"
                                max="3650"
                                step="1"
                                :label="__('Schedule history retention')"
                                :value="$keepDays"
                                :display="trans_choice('{0} Keep all recorded runs|{1} 1 day|[2,*] :days days', (int) $keepDays, ['days' => (int) $keepDays])"
                                :error="$errors->first('keepDays')"
                                :help="__('How long completed schedule runs stay in the History tab before the pruner deletes old rows. Set 0 to keep all recorded runs.')"
                                tabular
                            />
                        </div>
                    @else
                        <dl class="grid gap-1 text-sm">
                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Schedule history retention') }}</dt>
                            <dd class="text-ink tabular-nums">
                                {{ trans_choice('{0} Keep all recorded runs|{1} 1 day|[2,*] :days days', (int) $keepDays, ['days' => (int) $keepDays]) }}
                            </dd>
                            <dd class="max-w-xl text-xs leading-5 text-muted">
                                {{ __('How long completed schedule runs stay in the History tab before the pruner deletes old rows. Set 0 to keep all recorded runs.') }}
                            </dd>
                        </dl>
                    @endif
                </x-ui.card>
            </x-ui.tab>
        </x-ui.tabs>
    </div>
</div>
