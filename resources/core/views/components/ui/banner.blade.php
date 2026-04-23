@props([
    'variant' => 'warning',
    'title' => null,
    'description' => null,
    'icon' => null,
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
    'info' => [
        'bg' => 'bg-status-info-subtle',
        'border' => 'border-status-info-border',
        'text' => 'text-status-info',
        'icon' => 'heroicon-o-information-circle',
    ],
    default => [
        'bg' => 'bg-status-warning-subtle',
        'border' => 'border-status-warning-border',
        'text' => 'text-status-warning',
        'icon' => 'heroicon-o-exclamation-triangle',
    ],
};

$resolvedIcon = $icon ?: $config['icon'];
@endphp

<div {{ $attributes->class(["flex items-center justify-between gap-3 h-8 px-4 border-b {$config['bg']} {$config['border']}"]) }}>
    <div class="min-w-0 flex items-center gap-2">
        <x-icon :name="$resolvedIcon" class="w-3.5 h-3.5 shrink-0 {{ $config['text'] }}" />
        <div class="min-w-0">
            @if($title)
                <div class="text-xs font-medium leading-tight {{ $config['text'] }}">{{ $title }}</div>
            @endif
            @if($description)
                <div class="text-[11px] leading-tight text-muted truncate">{{ $description }}</div>
            @endif
        </div>
    </div>

    @if(isset($action))
        <div class="shrink-0">
            {{ $action }}
        </div>
    @endif
</div>

