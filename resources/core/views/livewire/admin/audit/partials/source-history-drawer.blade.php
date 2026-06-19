<x-ui.inspector-drawer
    wire:model="sourceHistoryDrawerOpen"
    close-action="closeSourceHistory"
    labelledby="audit-source-history-title"
>
            <header class="border-b border-border-default p-card-inner">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Record history') }}</p>
                        <h2 id="audit-source-history-title" class="mt-1 text-lg font-medium tracking-tight text-ink">
                            {{ $sourceHistoryTitle !== '' ? $sourceHistoryTitle : __('History') }}
                        </h2>
                        <p class="mt-1 text-sm text-muted">
                            {{ trans_choice(':count mutation|:count mutations', count($sourceHistory['entries'] ?? []), ['count' => count($sourceHistory['entries'] ?? [])]) }}
                        </p>
                        @if (($sourceHistory['has_more'] ?? false) && $sourceHistoryAllUrl !== '')
                            <p class="mt-1 text-xs text-muted">
                                {{ __('Showing the latest :limit changes.', ['limit' => $sourceHistory['limit'] ?? 25]) }}
                                <a href="{{ $sourceHistoryAllUrl }}" wire:navigate class="font-medium text-accent hover:underline">
                                    {{ __('Open Data Mutations') }}
                                </a>
                            </p>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <x-ui.inspector-default-width-button />

                        <button
                            type="button"
                            wire:click="closeSourceHistory"
                            class="rounded p-1 text-muted transition-colors hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                            aria-label="{{ __('Close record history') }}"
                        >
                            <x-icon name="heroicon-o-x-mark" class="size-5" />
                        </button>
                    </div>
                </div>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto p-card-inner">
                @if (empty($sourceHistory['entries']))
                    <div class="rounded-2xl border border-border-default bg-surface-subtle p-4 text-sm text-muted">
                        {{ __('No mutations have been recorded for this record yet.') }}
                    </div>
                @else
                    <ol class="space-y-3">
                        @foreach ($sourceHistory['entries'] as $entry)
                            <li wire:key="source-history-{{ $entry['id'] }}" class="rounded-2xl border border-border-default bg-surface-card p-4 shadow-sm">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-ui.badge :variant="$entry['event_variant']">{{ $entry['event_label'] }}</x-ui.badge>
                                            <span class="font-mono text-xs text-muted">{{ $entry['auditable'] }}</span>
                                        </div>
                                        <p class="mt-2 text-sm font-medium text-ink">{{ $entry['summary'] }}</p>
                                        <p class="mt-0.5 text-xs text-muted">
                                            {{ $entry['actor'] }}
                                            @if (! empty($entry['actor_role']))
                                                <span aria-hidden="true">·</span>
                                                <span class="font-mono">{{ $entry['actor_role'] }}</span>
                                            @endif
                                            @if (! empty($entry['source']))
                                                <span aria-hidden="true">·</span>
                                                <span class="font-mono">{{ $entry['source'] }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="text-right text-xs text-muted">
                                        <div class="tabular-nums"><x-ui.datetime :value="$entry['occurred_at']" /></div>
                                        @if (! empty($entry['trace_id']))
                                            <button
                                                type="button"
                                                wire:click="openTrace('{{ $entry['trace_id'] }}')"
                                                class="mt-1 font-mono text-accent hover:underline focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                            >
                                                {{ $entry['formatted_trace_id'] }}
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-3 space-y-1 border-t border-border-default pt-3">
                                    @forelse ($entry['diffs'] ?? [] as $diff)
                                        <div class="flex items-baseline gap-2 font-mono text-xs">
                                            <span class="min-w-[120px] font-semibold text-muted">{{ $diff['field'] }}:</span>
                                            @if($diff['sensitive'])
                                                <code class="text-muted italic">{{ $diff['old'] }} → {{ $diff['new'] }}</code>
                                            @else
                                                <code class="text-status-danger">{{ $diff['old'] }}</code>
                                                <span class="text-muted">→</span>
                                                <code class="text-status-success">{{ $diff['new'] }}</code>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-xs italic text-muted">{{ __('No field changes recorded.') }}</p>
                                    @endforelse
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
</x-ui.inspector-drawer>
