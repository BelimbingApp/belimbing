@props([
    'variant' => 'success',
    'title' => null,
    'icon' => null,
    'iconClass' => 'h-5 w-5 shrink-0',
])

@php
$config = match($variant) {
    'success' => [
        'bg' => 'bg-status-success-subtle',
        'border' => 'border-status-success-border',
        'text' => 'text-status-success',
        'icon' => 'heroicon-o-check-circle',
    ],
    'danger', 'error' => [
        'bg' => 'bg-status-danger-subtle',
        'border' => 'border-status-danger-border',
        'text' => 'text-status-danger',
        'icon' => 'heroicon-o-exclamation-circle',
    ],
    'warning' => [
        'bg' => 'bg-status-warning-subtle',
        'border' => 'border-status-warning-border',
        'text' => 'text-status-warning',
        'icon' => 'heroicon-o-exclamation-triangle',
    ],
    'info' => [
        'bg' => 'bg-status-info-subtle',
        'border' => 'border-status-info-border',
        'text' => 'text-status-info',
        'icon' => 'heroicon-o-information-circle',
    ],
    default => [
        'bg' => 'bg-status-success-subtle',
        'border' => 'border-status-success-border',
        'text' => 'text-status-success',
        'icon' => 'heroicon-o-check-circle',
    ],
};

$resolvedIcon = $icon ?: $config['icon'];
@endphp

<div {{ $attributes->class(["flex items-start gap-2.5 p-card-inner border rounded-2xl {$config['bg']} {$config['border']} {$config['text']}"]) }}>
    <x-icon :name="$resolvedIcon" class="{{ $iconClass }}" />

    <div class="min-w-0 flex-1">
        @if($title)
            <div class="text-sm font-medium leading-snug">{{ $title }}</div>
        @endif

        <div @class(['text-sm leading-snug', 'mt-1' => (bool) $title])>
            {{ $slot }}
        </div>
    </div>
</div>
