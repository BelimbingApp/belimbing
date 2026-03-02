<x-layouts.app :title="__('Scheduled Tasks')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Scheduled Tasks')" :subtitle="__(':count registered scheduled commands', ['count' => $totalCount])" />

        <x-ui.card>
            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Command') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Schedule') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Description') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Timezone') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Flags') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($events as $event)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ \App\Base\Schedule\Controllers\ScheduledTaskController::cleanCommand($event->command) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted font-mono">{{ $event->expression }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $event->description ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $event->timezone ?? config('app.timezone') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <div class="flex flex-wrap gap-1">
                                        @if ($event->withoutOverlapping)<x-ui.badge>{{ __('No Overlap') }}</x-ui.badge>@endif
                                        @if ($event->onOneServer)<x-ui.badge>{{ __('One Server') }}</x-ui.badge>@endif
                                        @if ($event->runInBackground)<x-ui.badge>{{ __('Background') }}</x-ui.badge>@endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No scheduled tasks registered.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
