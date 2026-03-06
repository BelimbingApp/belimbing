@props([
    'status' => null,
    'compact' => false,
    'showRole' => true,
    'showShortcut' => false,
])

@php
    $iconClasses = $compact ? 'w-3.5 h-3.5' : 'w-4 h-4';
    $nameClasses = $compact ? 'text-xs font-medium text-current' : 'text-sm font-medium text-current';
    $badgeClasses = $compact ? 'text-[9px] uppercase tracking-wider font-semibold' : 'text-[10px] uppercase tracking-wider font-semibold';
    $shortcutClasses = $compact ? 'text-[10px] text-muted' : 'text-xs text-muted';

    $statusVariant = match ($status) {
        'online' => 'success',
        'inactive' => 'warning',
        default => null,
    };

    $statusLabel = match ($status) {
        'online' => __('Online'),
        'inactive' => __('Inactive'),
        default => null,
    };
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1.5') }}>
    <x-icon name="heroicon-o-sparkles" class="{{ $iconClasses }} text-accent" />
    <span class="{{ $nameClasses }}">{{ __('Lara') }}</span>
    @if ($showRole)
        <x-ui.badge variant="accent" class="{{ $badgeClasses }}">
            {{ __('System DW') }}
        </x-ui.badge>
    @endif
    @if ($statusLabel !== null && $statusVariant !== null)
        <x-ui.badge :variant="$statusVariant" class="{{ $badgeClasses }}">
            {{ $statusLabel }}
        </x-ui.badge>
    @endif
    @if ($showShortcut)
        <span class="{{ $shortcutClasses }}">({{ __('Ctrl+K') }})</span>
    @endif
</span>
