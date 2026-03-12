<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'name',
    'role' => 'Digital Worker',
    'icon' => 'heroicon-o-cpu-chip',
    'shortcut' => null,
    'compact' => false,
    'showRole' => true,
])

@php
    $iconClasses = $compact ? 'w-3.5 h-3.5' : 'w-4 h-4';
    $nameClasses = $compact ? 'text-xs font-medium text-current' : 'text-sm font-medium text-current';
    $badgeClasses = $compact ? 'text-[9px] uppercase tracking-wider font-semibold' : 'text-[10px] uppercase tracking-wider font-semibold';
    $shortcutClasses = $compact ? 'text-[10px] text-muted' : 'text-xs text-muted';
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1.5') }}>
    <x-icon name="{{ $icon }}" class="{{ $iconClasses }} text-accent" />
    <span class="{{ $nameClasses }}">{{ __($name) }}</span>
    @if ($showRole)
        <x-ui.badge variant="accent" class="{{ $badgeClasses }}">
            {{ __($role) }}
        </x-ui.badge>
    @endif
    @if ($shortcut !== null)
        <span class="{{ $shortcutClasses }}">({{ __($shortcut) }})</span>
    @endif
</span>
