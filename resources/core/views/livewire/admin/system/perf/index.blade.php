{{-- @var \App\Base\Perf\Livewire\Dashboard\Index $this --}}
@php
    /** Compact duration: 412 ms below a second, 1.41 s above. */
    $ms = static fn (float $value): string => $value >= 1000
        ? number_format($value / 1000, 2).' '.__('s')
        : number_format($value).' '.__('ms');

    // Log-scale y positions matching the component's 10 ms – 30 s bounds.
    $gridline = static fn (float $milliseconds): float => 100 - ((log10($milliseconds) - 1) / (log10(30000) - 1) * 100);
@endphp

<div class="space-y-section-gap">
    <x-slot name="title">{{ __('Performance') }}</x-slot>

    <x-ui.page-header :title="__('Performance')" :subtitle="__('Where request time goes — wall, database, subprocess — from the live request log.')">
        <x-slot name="actions">
            <div @segmented-control-change="$wire.setWindow($event.detail.value)">
                <x-ui.segmented-control
                    :options="collect($windows)->map(fn (string $w): array => ['value' => $w, 'label' => $w])->all()"
                    :value="$window"
                    :label="__('Time window')"
                />
            </div>
        </x-slot>
    </x-ui.page-header>

    @if ($summary['requests'] === 0)
        <x-ui.card>
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <x-icon name="heroicon-o-bolt" class="h-6 w-6 text-muted" />
                <p class="text-sm font-medium text-ink">{{ __('No requests recorded in the last :window', ['window' => $window]) }}</p>
                <p class="max-w-md text-sm text-muted">
                    {{ __('Browse the app and this page fills itself. Recording is controlled by PERF_LOG_ENABLED; the log lives in storage/logs/perf-*.jsonl.') }}
                </p>
            </div>
        </x-ui.card>
    @else
        <x-ui.stat-strip min-width="52rem" class="rounded-2xl border border-border-default bg-surface-card p-card-inner shadow-sm">
            <x-ui.stat :label="__('Requests')">{{ number_format($summary['requests']) }}</x-ui.stat>
            <x-ui.stat :label="__('p50')">{{ $ms($summary['p50']) }}</x-ui.stat>
            <x-ui.stat :label="__('p95')">{{ $ms($summary['p95']) }}</x-ui.stat>
            <x-ui.stat :label="__('DB share')">
                {{ number_format($summary['db_share'], 1) }}%
                <x-slot name="sub">{{ __('of wall time') }}</x-slot>
            </x-ui.stat>
            <x-ui.stat :label="__('Subprocess time')">{{ $ms($summary['proc_ms']) }}</x-ui.stat>
            <x-ui.stat :label="__('Cache hits')">
                @if ($summary['cache_rate'] !== null)
                    {{ number_format($summary['cache_rate'], 1) }}%
                @else
                    &mdash;
                @endif
            </x-ui.stat>
        </x-ui.stat-strip>

        {{-- Latency scatter: one dot per request, log-scale, native tooltips. --}}
        <section aria-label="{{ __('Latency timeline') }}">
            <x-ui.card>
                <div class="flex items-baseline justify-between gap-4 px-1 pt-1">
                    <h2 class="text-sm font-medium text-ink">{{ __('Latency') }}</h2>
                    <p class="text-xs text-muted">
                        <span class="mr-3 inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-status-warning"></span>{{ __('over 1 s') }}</span>
                        <span class="inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-status-danger"></span>{{ __('over 5 s or failed') }}</span>
                    </p>
                </div>
                <div class="px-1 pb-1">
                    <svg class="mt-2 h-28 w-full" role="img" aria-label="{{ __('Request durations across the window, log scale') }}">
                        @foreach ([10000 => '10s', 1000 => '1s', 100 => '100ms'] as $line => $label)
                            <line x1="0%" x2="100%" y1="{{ $gridline($line) }}%" y2="{{ $gridline($line) }}%" class="stroke-current text-border-default" stroke-width="1" stroke-dasharray="2 4" />
                            <text x="100%" y="{{ $gridline($line) - 2.5 }}%" text-anchor="end" class="fill-current text-muted" font-size="9">{{ $label }}</text>
                        @endforeach
                        @foreach ($timeline['points'] as $point)
                            <circle
                                cx="{{ number_format($point['x'], 2) }}%"
                                cy="{{ number_format($point['y'], 2) }}%"
                                r="2.5"
                                @class([
                                    'fill-current',
                                    'text-status-danger' => $point['tone'] === 'danger',
                                    'text-status-warning' => $point['tone'] === 'warning',
                                    'text-muted opacity-45' => $point['tone'] === 'ok',
                                ])
                            ><title>{{ $point['label'] }}</title></circle>
                        @endforeach
                    </svg>
                    <div class="mt-1 flex justify-between text-[10px] uppercase tracking-wide text-muted tabular-nums">
                        <span>{{ $timeline['from'] }}</span>
                        <span>{{ $timeline['to'] }}</span>
                    </div>
                </div>
            </x-ui.card>
        </section>

        {{-- The centerpiece: per-route composition of the average request. --}}
        <section aria-label="{{ __('Time by route') }}" class="space-y-1.5">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-sm font-medium text-ink">{{ __('Where the time goes') }}</h2>
                <p class="text-xs text-muted">
                    <span class="mr-3 inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm bg-status-info"></span>{{ __('Database') }}</span>
                    <span class="mr-3 inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm bg-status-warning"></span>{{ __('Subprocess') }}</span>
                    <span class="inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm bg-border-input"></span>{{ __('Everything else') }}</span>
                </p>
            </div>

            <x-ui.table :caption="__('Routes by p95 duration')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Route') }}</x-ui.th>
                        <x-ui.th width="34%">{{ __('Average request') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('Hits') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('p50') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('p95') }}</x-ui.th>
                        <x-ui.th numeric nowrap>{{ __('Avg queries') }}</x-ui.th>
                        <x-ui.th numeric nowrap>{{ __('Avg procs') }}</x-ui.th>
                    </tr>
                </x-slot>

                <x-slot name="body">
                    @foreach ($routes as $route)
                        @php
                            $compositionLabel = __(':total average — :db% database, :proc% subprocess', [
                                'total' => $ms($route['avg_ms']),
                                'db' => number_format($route['db_pct']),
                                'proc' => number_format($route['proc_pct']),
                            ]);
                        @endphp
                        <tr wire:key="route-{{ $route['route'] }}">
                            <td class="max-w-64 truncate px-table-cell-x py-table-cell-y font-mono text-xs text-ink" title="{{ $route['route'] }}">{{ $route['route'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y">
                                <div
                                    class="flex h-2.5 overflow-hidden rounded-full motion-safe:transition-[width] motion-safe:duration-200 motion-safe:ease-out"
                                    style="width: {{ number_format($route['bar_width'], 2) }}%"
                                    aria-hidden="true"
                                >
                                    <div class="h-full shrink-0 bg-status-info" style="width: {{ number_format($route['db_pct'], 2) }}%"></div>
                                    <div class="h-full shrink-0 bg-status-warning" style="width: {{ number_format($route['proc_pct'], 2) }}%"></div>
                                    <div class="h-full flex-1 bg-border-input"></div>
                                </div>
                                <span class="sr-only">{{ $compositionLabel }}</span>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ number_format($route['hits']) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-muted">{{ $ms($route['p50']) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums font-medium text-ink">{{ $ms($route['p95']) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-muted">{{ number_format($route['avg_queries'], 1) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums {{ $route['avg_procs'] > 0 ? 'font-medium text-status-warning' : 'text-muted' }}">{{ number_format($route['avg_procs'], 1) }}</td>
                        </tr>
                    @endforeach
                </x-slot>
            </x-ui.table>
        </section>

        <section aria-label="{{ __('Slowest requests') }}" class="space-y-1.5">
            <h2 class="text-sm font-medium text-ink">{{ __('Slowest requests') }}</h2>

            <x-ui.table :caption="__('Slowest individual requests in the window')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th nowrap>{{ __('Time') }}</x-ui.th>
                        <x-ui.th>{{ __('Request') }}</x-ui.th>
                        <x-ui.th>{{ __('Status') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('Duration') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('DB') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('Queries') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('Procs') }}</x-ui.th>
                        <x-ui.th numeric nowrap>{{ __('Size') }}</x-ui.th>
                    </tr>
                </x-slot>

                <x-slot name="body">
                    @foreach ($slowest as $index => $request)
                        <tr wire:key="slow-{{ $index }}">
                            <td class="whitespace-nowrap px-table-cell-x py-table-cell-y tabular-nums text-muted">{{ substr((string) ($request['ts'] ?? ''), 11, 8) }}</td>
                            <td class="max-w-80 px-table-cell-x py-table-cell-y">
                                <span class="font-mono text-xs text-ink">{{ $request['method'] ?? '' }} <span class="text-muted">{{ $request['path'] ?? '' }}</span></span>
                                @if ($request['navigate'] ?? false)
                                    <x-ui.badge class="ml-1" tooltip="{{ __('Partial render via wire:navigate — the shared shell was skipped.') }}">{{ __('partial') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y">
                                @php $status = (int) ($request['status'] ?? 0); @endphp
                                <x-ui.badge :variant="$status >= 500 ? 'danger' : ($status >= 400 ? 'warning' : 'default')">{{ $status }}</x-ui.badge>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums font-medium text-ink">{{ $ms((float) ($request['ms'] ?? 0)) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-muted">{{ $ms((float) ($request['db_ms'] ?? 0)) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-muted">{{ number_format((int) ($request['queries'] ?? 0)) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums {{ ($request['procs'] ?? 0) > 0 ? 'font-medium text-status-warning' : 'text-muted' }}">{{ number_format((int) ($request['procs'] ?? 0)) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-muted">
                                @if (isset($request['resp_bytes']))
                                    {{ number_format($request['resp_bytes'] / 1024) }} {{ __('KB') }}
                                @else
                                    &mdash;
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-slot>
            </x-ui.table>
        </section>
    @endif

    {{-- The log is the product; this page is a demonstration of it. --}}
    <p class="text-xs text-muted">
        {{ __('Read-only view over storage/logs/perf-*.jsonl — the same log agents query with') }}
        <code class="rounded bg-surface-subtle px-1 py-0.5 font-mono text-[11px] text-ink">php artisan perf:slowest</code>
        {{ __('and') }}
        <code class="rounded bg-surface-subtle px-1 py-0.5 font-mono text-[11px] text-ink">php artisan perf:requests</code>.
    </p>
</div>
