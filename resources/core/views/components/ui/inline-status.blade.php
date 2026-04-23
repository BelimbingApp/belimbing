@props([
    'variant' => 'info',
    'text' => null,
])

@php
$config = match($variant) {
    'success' => [
        'text' => 'text-status-success',
        'icon' => 'heroicon-o-check-circle',
    ],
    'danger', 'error' => [
        'text' => 'text-status-danger',
        'icon' => 'heroicon-o-x-circle',
    ],
    'warning' => [
        'text' => 'text-status-warning',
        'icon' => 'heroicon-o-exclamation-triangle',
    ],
    default => [
        'text' => 'text-status-info',
        'icon' => 'heroicon-o-information-circle',
    ],
};
@endphp

<div {{ $attributes->class("flex items-center gap-1.5 text-xs") }}>
    @if(isset($icon))
        {{ $icon }}
    @else
        <x-icon :name="$config['icon']" class="h-3.5 w-3.5 shrink-0 {{ $config['text'] }}" />
    @endif

    @if($text !== null)
        <span class="{{ $config['text'] }}">{{ $text }}</span>
    @else
        {{ $slot }}
    @endif

    @if(isset($action))
        {{ $action }}
    @endif
</div>

