{{-- GitHub-style branch relationship indicator (#482): behind grows left in
     amber (the only direction that ever demands work), ahead grows right in
     blue (informational — a fork's own commits are its normal state). Widths
     are proportional within each half on a log-ish scale so 41 does not
     flatten 5 into invisibility; counts stay the authoritative reading and
     the bar is aria-hidden decoration beside them. --}}
@props([
    'ahead' => null,
    'behind' => null,
    'aheadLabel' => null,
    'behindLabel' => null,
])

@php
    $known = $ahead !== null && $behind !== null;
    $inSync = $known && (int) $ahead === 0 && (int) $behind === 0;
    $scale = function (int $count): int {
        // 0 → 0; 1..∞ compresses into 12..56px. log keeps small counts
        // visible next to large ones without lying about which is bigger.
        return $count === 0 ? 0 : (int) min(56, round(12 + 14 * log10($count + 1) * 2));
    };
@endphp

@if (! $known)
    <span {{ $attributes->class(['text-xs text-muted']) }}>{{ __('—') }}</span>
@elseif ($inSync)
    <span {{ $attributes->class(['inline-flex items-center gap-1.5']) }}>
        <x-ui.badge variant="success">{{ __('In sync') }}</x-ui.badge>
    </span>
@else
    <span {{ $attributes->class(['inline-flex items-center gap-2 whitespace-nowrap']) }}
          title="{{ trim(($behindLabel ?? trans_choice('{1} :count behind|[2,*] :count behind', (int) $behind, ['count' => $behind])).' · '.($aheadLabel ?? trans_choice('{1} :count ahead|[2,*] :count ahead', (int) $ahead, ['count' => $ahead]))) }}">
        <span class="text-xs font-medium tabular-nums {{ (int) $behind > 0 ? 'text-status-warning' : 'text-muted' }}">{{ $behind }}&darr;</span>
        <span class="inline-flex w-[7.5rem] items-center" aria-hidden="true">
            <span class="flex w-1/2 justify-end">
                <span class="h-2 rounded-l-full bg-status-warning/80" style="width: {{ $scale((int) $behind) }}px"></span>
            </span>
            <span class="mx-px h-3.5 w-px shrink-0 bg-border-default"></span>
            <span class="flex w-1/2">
                <span class="h-2 rounded-r-full bg-status-info/80" style="width: {{ $scale((int) $ahead) }}px"></span>
            </span>
        </span>
        <span class="text-xs font-medium tabular-nums {{ (int) $ahead > 0 ? 'text-status-info' : 'text-muted' }}">{{ $ahead }}&uarr;</span>
    </span>
@endif
