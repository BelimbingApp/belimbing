@props([
    'variant' => 'success',
    'role' => null,
    'ariaLive' => null,
    'announce' => true,
])

@php
    $status = \App\Base\Foundation\Enums\StatusVariant::fromLabel($variant);
    $config = $status->classes();

    $resolvedRole = null;
    $resolvedAriaLive = null;

    if ($announce) {
        $resolvedRole = $role ?? match ($status) {
            \App\Base\Foundation\Enums\StatusVariant::Error,
            \App\Base\Foundation\Enums\StatusVariant::Warning => 'alert',
            default => 'status',
        };
        $resolvedAriaLive = $ariaLive ?? ($resolvedRole === 'alert' ? 'assertive' : 'polite');
    }

    $alertAttributes = $attributes->class([
        "flex items-center gap-3 p-4 border rounded-2xl {$config['bg']} {$config['border']} {$config['text']}",
    ]);

    if ($resolvedRole !== null) {
        $alertAttributes = $alertAttributes->merge([
            'role' => $resolvedRole,
            'aria-live' => $resolvedAriaLive,
        ]);
    }
@endphp

<div {{ $alertAttributes }}>
    <x-icon :name="$status->icon()" class="w-5 h-5 shrink-0" />
    <span class="text-sm">{{ $slot }}</span>
</div>
