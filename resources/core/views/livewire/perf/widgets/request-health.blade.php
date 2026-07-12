@php /** @var \App\Base\Perf\Livewire\Widgets\RequestHealth $this */ @endphp
@php
    $ms = static fn (float $value): string => $value >= 1000
        ? number_format($value / 1000, 2).' '.__('s')
        : number_format($value).' '.__('ms');
@endphp
<div>
    <x-ui.card>
        <x-ui.widget-header :title="__('Performance')" :href="$perfUrl" icon="heroicon-o-bolt" />
        <x-ui.stat-strip>
            <x-ui.stat :label="__('Requests · 24 h')">{{ number_format($summary['requests']) }}</x-ui.stat>
            <x-ui.stat :label="__('p50')">{{ $ms($summary['p50']) }}</x-ui.stat>
            <x-ui.stat :label="__('p95')">{{ $ms($summary['p95']) }}</x-ui.stat>
            <x-ui.stat :label="__('Regressing')">
                @if ($regressionCount > 0)
                    <span class="text-status-warning">{{ $regressionCount }}</span>
                @else
                    0
                @endif
            </x-ui.stat>
        </x-ui.stat-strip>
        @if ($summary['slowest_route'] !== null)
            <p class="mt-2 truncate text-xs text-muted" title="{{ $summary['slowest_route'] }}">
                {{ __('Slowest: :route', ['route' => $summary['slowest_route']]) }}
            </p>
        @endif
    </x-ui.card>
</div>
