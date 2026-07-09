<x-ui.card>
    <x-ui.table container="flush" :caption="__('Registered scheduled tasks')">
        <x-slot name="head">
            <tr>
                <x-ui.sortable-th
                    column="command"
                    :sort-by="$sortBy"
                    :sort-dir="$sortDir"
                    action="sort('command')"
                    :label="__('Command')"
                />
                <x-ui.sortable-th
                    column="expression"
                    :sort-by="$sortBy"
                    :sort-dir="$sortDir"
                    action="sort('expression')"
                    :label="__('Schedule')"
                />
                <x-ui.sortable-th
                    column="last_run"
                    :sort-by="$sortBy"
                    :sort-dir="$sortDir"
                    action="sort('last_run')"
                    :label="__('Last run')"
                />
                <x-ui.sortable-th
                    column="next_run"
                    :sort-by="$sortBy"
                    :sort-dir="$sortDir"
                    action="sort('next_run')"
                    :label="__('Next run')"
                />
                <x-ui.sortable-th
                    column="status"
                    :sort-by="$sortBy"
                    :sort-dir="$sortDir"
                    action="sort('status')"
                    :label="__('Status')"
                />
                <x-ui.sortable-th
                    column="flags"
                    :sort-by="$sortBy"
                    :sort-dir="$sortDir"
                    action="sort('flags')"
                    :label="__('Flags')"
                />
                <x-ui.th>{{ __('Output') }}</x-ui.th>
            </tr>
        </x-slot>

        @forelse($rows as $row)
            <tr wire:key="event-{{ md5($row->commandKey) }}">
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">
                    <div class="flex items-center gap-1.5">
                        @if ($canRun)
                            <x-ui.icon-action
                                icon="heroicon-o-play"
                                :label="__('Run now')"
                                :title="__('Queue :command to run now', ['command' => $row->command])"
                                wire:click="runNow({{ \Illuminate\Support\Js::from($row->commandKey) }})"
                                wire:loading.attr="disabled"
                                wire:target="runNow"
                            />
                        @endif
                        <span @if ($row->description) title="{{ $row->description }}" @endif>{{ $row->command }}</span>
                        @if ($row->runRefLabel())
                            <span class="font-mono text-xs text-muted" title="{{ __('Last-run row id') }}">{{ $row->runRefLabel() }}</span>
                        @endif
                    </div>
                </td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted font-mono">{{ $row->expression }}</td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                    @if ($row->lastRunAt())
                        <x-ui.datetime :value="$row->lastRunAt()" />
                    @else
                        {{ __('Never') }}
                    @endif
                    @if ($row->runtimeLabel())
                        <span class="ml-1 text-xs text-muted">({{ $row->runtimeLabel() }})</span>
                    @endif
                </td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                    @if ($row->nextRunAt)
                        <x-ui.datetime :value="$row->nextRunAt" />
                    @else
                        {{ __('—') }}
                    @endif
                </td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                    <x-ui.badge :variant="$row->statusVariant()">{{ $row->statusLabel() }}</x-ui.badge>
                </td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                    <div class="flex flex-wrap gap-1">
                        @if (in_array('withoutOverlapping', $row->flags, true))
                            <x-ui.badge>{{ __('No Overlap') }}</x-ui.badge>
                        @endif
                        @if (in_array('onOneServer', $row->flags, true))
                            <x-ui.badge>{{ __('One Server') }}</x-ui.badge>
                        @endif
                        @if (in_array('runInBackground', $row->flags, true))
                            <x-ui.badge>{{ __('Background') }}</x-ui.badge>
                        @endif
                    </div>
                </td>
                <td class="px-table-cell-x py-table-cell-y max-w-96 align-top text-xs text-muted">
                    @if ($row->lastOutputPreview)
                        <pre class="max-h-20 overflow-hidden whitespace-pre-wrap break-words font-mono leading-5 text-ink">{{ $row->lastOutputPreview }}</pre>
                    @else
                        <span>{{ __('—') }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No scheduled tasks registered.') }}</td>
            </tr>
        @endforelse
    </x-ui.table>
</x-ui.card>
