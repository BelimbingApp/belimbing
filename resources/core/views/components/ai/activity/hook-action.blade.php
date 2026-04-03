<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'stage',
    'action',
    'tool' => null,
    'toolsRemoved' => [],
    'reason' => null,
    'source' => null,
    'timestamp' => null,
])

@php
    $label = match ($action) {
        'tools_removed' => trans_choice(
            '{1} :count tool hidden by policy|[2,*] :count tools hidden by policy',
            count($toolsRemoved),
            ['count' => count($toolsRemoved)],
        ),
        'tool_denied' => __(':tool denied', ['tool' => $tool ?? 'Tool']),
        default => __('Hook action'),
    };

    $sourceLabel = match ($source) {
        'authorization' => __('authz'),
        'hook' => __('hook'),
        default => null,
    };
@endphp

<div class="flex gap-2 py-1">
    <div class="shrink-0 mt-0.5">
        <x-icon name="heroicon-o-shield-check" class="w-4 h-4 text-amber-500/70" />
    </div>
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-1.5 text-xs text-muted">
            <span>{{ $label }}</span>
            @if ($sourceLabel)
                <span class="inline-flex items-center rounded-full bg-amber-500/10 px-1.5 py-0.5 text-[9px] font-medium text-amber-700 dark:text-amber-400">{{ $sourceLabel }}</span>
            @endif
            @if ($reason)
                <span class="text-muted/70 truncate max-w-[16rem]" title="{{ $reason }}">— {{ $reason }}</span>
            @endif
        </div>
        @if ($action === 'tools_removed' && count($toolsRemoved) > 0)
            <div class="mt-0.5 text-[10px] text-muted/60 truncate">{{ implode(', ', $toolsRemoved) }}</div>
        @endif
    </div>
</div>
