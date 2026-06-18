<div
    x-data="{ open: @entangle('traceDrawerOpen') }"
    x-show="open"
    x-cloak
    @keydown.escape.window="$wire.closeTrace()"
    class="fixed inset-0 z-50 overflow-hidden"
    style="display: none;"
>
    <div
        x-show="open"
        x-transition.opacity.duration.150ms
        @click="$wire.closeTrace()"
        class="absolute inset-0 bg-black/50"
    ></div>

    <div class="absolute inset-y-0 right-0 flex max-w-full pl-8 sm:pl-12">
        <section
            x-show="open"
            x-transition:enter="transform transition ease-out duration-200 motion-reduce:duration-0"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform transition ease-in duration-150 motion-reduce:duration-0"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            @click.stop
            class="flex h-full w-screen max-w-3xl flex-col border-l border-border-default bg-surface-card shadow-xl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="audit-trace-drawer-title"
        >
            <header class="border-b border-border-default p-card-inner">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Trace timeline') }}</p>
                        <h2 id="audit-trace-drawer-title" class="mt-1 font-mono text-lg font-medium tracking-tight text-ink">
                            {{ $traceTimeline['formatted_trace_id'] ?? __('No trace selected') }}
                        </h2>
                        @if (! empty($traceTimeline))
                            <p class="mt-1 text-sm text-muted">
                                {{ trans_choice(':count action|:count actions', $traceTimeline['action_count'] ?? 0, ['count' => $traceTimeline['action_count'] ?? 0]) }}
                                ·
                                {{ trans_choice(':count mutation|:count mutations', $traceTimeline['mutation_count'] ?? 0, ['count' => $traceTimeline['mutation_count'] ?? 0]) }}
                            </p>
                            @if (! empty($traceTimeline['first_at']) || ! empty($traceTimeline['last_at']))
                                <p class="mt-1 text-xs text-muted">
                                    {{ __('Window') }}:
                                    <x-ui.datetime :value="$traceTimeline['first_at'] ?? null" />
                                    <span aria-hidden="true">→</span>
                                    <x-ui.datetime :value="$traceTimeline['last_at'] ?? null" />
                                </p>
                            @endif
                            @if (($traceTimeline['action_count'] ?? 0) === 0 && ($traceTimeline['mutation_count'] ?? 0) > 0)
                                <p class="mt-1 text-xs text-status-warning">
                                    {{ __('No action rows are available for this trace; action context may have been pruned or not captured.') }}
                                </p>
                            @endif
                        @endif
                    </div>

                    <button
                        type="button"
                        wire:click="closeTrace"
                        class="rounded p-1 text-muted transition-colors hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                        aria-label="{{ __('Close trace timeline') }}"
                    >
                        <x-icon name="heroicon-o-x-mark" class="size-5" />
                    </button>
                </div>

                @if (! empty($traceTimeline['actor_labels']))
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted">
                        <span class="font-semibold uppercase tracking-wider">{{ __('Actors') }}</span>
                        @foreach ($traceTimeline['actor_labels'] as $actorLabel)
                            <x-ui.badge>{{ $actorLabel }}</x-ui.badge>
                        @endforeach
                    </div>
                @endif
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto p-card-inner">
                @if (empty($traceTimeline['entries']))
                    <div class="rounded-2xl border border-border-default bg-surface-subtle p-4 text-sm text-muted">
                        {{ __('No audit rows are available for this trace. The action may have been pruned or the trace may be incomplete.') }}
                    </div>
                @else
                    <ol class="space-y-3">
                        @foreach ($traceTimeline['entries'] as $entry)
                            <li wire:key="trace-{{ $entry['kind'] }}-{{ $entry['id'] }}" class="rounded-2xl border border-border-default bg-surface-card p-4 shadow-sm">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-ui.badge :variant="$entry['kind'] === 'mutation' ? 'info' : 'default'">{{ $entry['source'] }}</x-ui.badge>
                                            <x-ui.badge :variant="$entry['variant']">{{ $entry['result'] }}</x-ui.badge>
                                            <span class="font-mono text-xs text-muted">{{ $entry['event'] }}</span>
                                        </div>
                                        <p class="mt-2 text-sm font-medium text-ink">{{ $entry['summary'] }}</p>
                                        @if (! empty($entry['context']))
                                            <p class="mt-0.5 truncate text-xs text-muted" title="{{ $entry['context'] }}">{{ $entry['context'] }}</p>
                                        @endif
                                    </div>
                                    <div class="text-right text-xs text-muted">
                                        <div class="tabular-nums"><x-ui.datetime :value="$entry['occurred_at']" /></div>
                                        <div class="mt-0.5">{{ $entry['actor'] }}</div>
                                        @if (! empty($entry['actor_role']))
                                            <div class="mt-0.5 font-mono">{{ $entry['actor_role'] }}</div>
                                        @endif
                                    </div>
                                </div>

                                @if ($entry['kind'] === 'mutation')
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
                                @else
                                    @if (! empty($entry['url']) || ! empty($entry['payload_json']))
                                        <details open class="mt-3 border-t border-border-default pt-3 text-xs text-muted">
                                            <summary class="cursor-pointer font-medium text-accent hover:underline">{{ __('Raw action detail') }}</summary>
                                            @if (! empty($entry['url']))
                                                <div class="mt-2">
                                                    <span class="font-semibold uppercase tracking-wider">{{ __('URL') }}</span>
                                                    <div class="mt-1 break-all font-mono text-ink">{{ $entry['url'] }}</div>
                                                </div>
                                            @endif
                                            @if (! empty($entry['payload_json']))
                                                <pre class="mt-2 max-h-64 overflow-auto rounded-lg border border-border-default bg-surface-subtle p-3 font-mono text-xs text-ink">{{ $entry['payload_json'] }}</pre>
                                            @endif
                                        </details>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </section>
    </div>
</div>
