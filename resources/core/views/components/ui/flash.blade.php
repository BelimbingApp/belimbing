@props([
    'variant' => 'success',
    'title' => null,
    'description' => null,
])

@php
    $status = \App\Base\Foundation\Enums\StatusVariant::fromLabel($variant);
    $config = $status->classes();
@endphp

<div {{ $attributes->class(["pointer-events-auto w-full rounded-2xl border px-4 py-3 shadow-lg shadow-black/5 {$config['bg']} {$config['border']} {$config['text']}"]) }}>
    <div class="flex items-start gap-3">
        <x-icon :name="$status->icon()" class="mt-0.5 h-5 w-5 shrink-0" />
        <div class="min-w-0 flex-1 space-y-1">
            @if ($title)
                <p class="text-sm font-medium">{{ $title }}</p>
            @endif
            @if ($description)
                <p class="text-xs leading-5 opacity-90">{{ $description }}</p>
            @endif
            @if (trim((string) $slot) !== '')
                <div class="text-xs leading-5 opacity-90">{{ $slot }}</div>
            @endif
        </div>
    </div>
</div>

