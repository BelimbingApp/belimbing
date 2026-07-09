@php
    use App\Base\Schedule\Support\ScheduleRunStatuses;
@endphp

<x-ui.card>
    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-end">
        <div class="min-w-0 flex-1">
            <x-ui.search-input
                id="scheduled-tasks-history-search"
                wire:model.live.debounce.300ms="historySearch"
                placeholder="{{ __('Search command or output...') }}"
            />
        </div>
        <div class="w-full sm:w-48">
            <x-ui.select
                id="scheduled-tasks-history-status"
                wire:model.live="historyStatus"
                :label="__('Status')"
                :block="true"
            >
                <option value="">{{ __('All statuses') }}</option>
                @foreach (ScheduleRunStatuses::recorded() as $status)
                    <option value="{{ $status }}">{{ ScheduleRunStatuses::label($status) }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    <x-ui.table container="flush" :caption="__('Scheduled task run history')">
        <x-slot name="head">
            <tr>
                <x-ui.sortable-th
                    column="id"
                    :sort-by="$historySortBy"
                    :sort-dir="$historySortDir"
                    action="sortHistory('id')"
                    :label="__('ID')"
                />
                <x-ui.sortable-th
                    column="command"
                    :sort-by="$historySortBy"
                    :sort-dir="$historySortDir"
                    action="sortHistory('command')"
                    :label="__('Command')"
                />
                <x-ui.sortable-th
                    column="status"
                    :sort-by="$historySortBy"
                    :sort-dir="$historySortDir"
                    action="sortHistory('status')"
                    :label="__('Status')"
                />
                <x-ui.sortable-th
                    column="started_at"
                    :sort-by="$historySortBy"
                    :sort-dir="$historySortDir"
                    action="sortHistory('started_at')"
                    :label="__('Started')"
                />
                <x-ui.sortable-th
                    column="finished_at"
                    :sort-by="$historySortBy"
                    :sort-dir="$historySortDir"
                    action="sortHistory('finished_at')"
                    :label="__('Finished')"
                />
                <x-ui.sortable-th
                    column="runtime_ms"
                    :sort-by="$historySortBy"
                    :sort-dir="$historySortDir"
                    action="sortHistory('runtime_ms')"
                    :label="__('Runtime')"
                />
                <x-ui.th>{{ __('Output') }}</x-ui.th>
            </tr>
        </x-slot>

        @forelse($historyRows as $history)
            <tr wire:key="history-{{ $history->id }}">
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-muted">#{{ $history->id }}</td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $history->command }}</td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                    <x-ui.badge :variant="ScheduleRunStatuses::variant($history->status)">
                        {{ ScheduleRunStatuses::label($history->status) }}
                    </x-ui.badge>
                </td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                    @if ($history->started_at)
                        <x-ui.datetime :value="$history->started_at" />
                    @else
                        {{ __('—') }}
                    @endif
                </td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                    @if ($history->finished_at)
                        <x-ui.datetime :value="$history->finished_at" />
                    @else
                        {{ __('—') }}
                    @endif
                </td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                    @if ($history->runtime_ms === null)
                        {{ __('—') }}
                    @elseif ($history->runtime_ms < 1000)
                        {{ __(':ms ms', ['ms' => $history->runtime_ms]) }}
                    @else
                        {{ __(':seconds s', ['seconds' => number_format($history->runtime_ms / 1000, 2)]) }}
                    @endif
                </td>
                <td class="px-table-cell-x py-table-cell-y max-w-96 align-top text-xs text-muted">
                    @if (filled($history->output))
                        <pre class="max-h-20 overflow-hidden whitespace-pre-wrap break-words font-mono leading-5 text-ink">{{ \Illuminate\Support\Str::limit(trim($history->output), 240) }}</pre>
                    @else
                        <span>{{ __('—') }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No run history yet.') }}</td>
            </tr>
        @endforelse
    </x-ui.table>

    <div class="mt-3">
        <x-ui.pagination :paginator="$historyRows" />
    </div>
</x-ui.card>
