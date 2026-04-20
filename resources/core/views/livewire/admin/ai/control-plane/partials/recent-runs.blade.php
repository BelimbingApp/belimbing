<x-ui.card>
    <div class="{{ $recentRunsCollapsed ? 'mb-1' : 'mb-4' }} flex items-center justify-between gap-3">
        <div>
            <button
                type="button"
                wire:click="$toggle('recentRunsCollapsed')"
                class="group inline-flex items-center gap-2 text-left text-sm font-medium text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 rounded-2xl"
                aria-controls="recent-runs-panel"
                aria-expanded="{{ $recentRunsCollapsed ? 'false' : 'true' }}"
            >
                <span>{{ __('Run Ledger') }}</span>
                <x-icon
                    name="heroicon-o-chevron-down"
                    class="{{ $recentRunsCollapsed ? 'rotate-0' : 'rotate-180' }} h-4 w-4 text-muted transition-transform duration-200 motion-reduce:transition-none"
                />
            </button>
            @if (! $recentRunsCollapsed)
                <p class="mt-1 text-xs text-muted">{{ __('Newest runs first. Inspecting a row loads transcript, prompt, and wire-log detail below.') }}</p>
            @endif
        </div>
        @if (! $recentRunsCollapsed)
            <x-ui.button wire:click="refreshInspectorLists" variant="secondary" size="sm">
                {{ __('Refresh') }}
            </x-ui.button>
        @endif
    </div>

    <div id="recent-runs-panel">
        @if (! $recentRunsCollapsed)
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="recentRunsSearch"
                    placeholder="{{ __('Search by run id...') }}"
                />
            </div>

            @if ($inspectionError)
                <div class="mb-2">
                    <x-ui.alert variant="warning">{{ $inspectionError }}</x-ui.alert>
                </div>
            @endif

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Run') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Agent') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Provider') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Turn') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($recentRuns as $run)
                            <tr wire:key="recent-run-{{ $run['run_id'] }}" class="hover:bg-surface-subtle/60 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y">
                                    <button
                                        type="button"
                                        wire:click="inspectRecentRun('{{ $run['run_id'] }}')"
                                        class="font-mono text-xs text-accent hover:underline"
                                    >
                                        {{ $run['run_id'] }}
                                    </button>
                                    <p class="mt-1 text-xs text-muted tabular-nums">{{ $run['started_at_display'] ?? $run['recorded_at_display'] ?? '—' }}</p>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-ink">{{ $run['employee_name'] }}</td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <p class="text-ink">{{ $run['provider'] }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ $run['model'] }}</p>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-ui.badge :variant="$run['status_color'] ?? ($run['outcome_color'] ?? 'secondary')">
                                        {{ $run['status_label'] ?? ($run['outcome_label'] ?? __('Unknown')) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    @if ($run['turn_id'])
                                        <button
                                            type="button"
                                            wire:click="inspectRecentTurn('{{ $run['turn_id'] }}')"
                                            class="font-mono text-xs text-accent hover:underline"
                                        >
                                            {{ \Illuminate\Support\Str::limit($run['turn_id'], 18, '...') }}
                                        </button>
                                    @else
                                        <span class="text-muted">---</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No runs have been recorded yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $recentRuns->links() }}
            </div>
        @endif
    </div>
</x-ui.card>
