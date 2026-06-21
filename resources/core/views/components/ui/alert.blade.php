@props(['variant' => 'success'])

@php
    $status = \App\Base\Foundation\Enums\StatusVariant::fromLabel($variant);
    $config = $status->classes();
@endphp

<div {{ $attributes->class(["flex items-center gap-3 p-4 border rounded-2xl {$config['bg']} {$config['border']} {$config['text']}"]) }}>
    <x-icon :name="$status->icon()" class="w-5 h-5 shrink-0" />
    <span class="text-sm">{{ $slot }}</span>
</div>
