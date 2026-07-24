@props([
    'show' => false,
    'labelledby' => null,
    'label' => null,
])

<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-show="show"
    x-cloak
    @keydown.escape.window="show = false"
    class="fixed inset-0 z-50 overflow-y-auto"
    @if($labelledby || $label)
        role="dialog"
        aria-modal="true"
    @endif
    @if($labelledby) aria-labelledby="{{ $labelledby }}" @endif
    @if($label) aria-label="{{ $label }}" @endif
    style="display: none;"
>
    <!-- Backdrop -->
    <div
        x-show="show"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="show = false"
        class="fixed inset-0 bg-black/50"
    ></div>

    <!-- Modal -->
    <div class="flex min-h-full items-center justify-center p-4">
        <div
            x-show="show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            @click.stop
            {{ $attributes->merge(['class' => 'relative bg-surface-card border border-border-default rounded-2xl shadow-xl w-full']) }}
        >
            {{ $slot }}
        </div>
    </div>
</div>
