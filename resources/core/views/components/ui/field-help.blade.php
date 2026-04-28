@props([
    'hint' => null,
    'details' => null,
])

<div
    {{ $attributes->class('min-w-0') }}
    x-data="{ open: false }"
    @keydown.escape.window="open = false"
>
    <div class="flex min-w-0 items-start gap-1">
        @if ($hint)
            <p class="min-w-0 text-[11px] leading-4 text-muted/80">{{ $hint }}</p>
        @endif

        @if ($details)
            <button
                type="button"
                class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-muted/70 transition-colors hover:text-muted focus:text-accent focus:outline-none"
                aria-label="{{ __('Field help') }}"
                aria-expanded="false"
                x-bind:aria-expanded="open.toString()"
                @click="open = ! open"
            >
                <x-icon name="heroicon-o-question-mark-circle" class="h-3.5 w-3.5" />
            </button>
        @endif
    </div>

    @if ($details)
        <div
            x-cloak
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-1"
            class="mt-1 max-w-prose text-xs font-normal normal-case leading-5 tracking-normal text-muted"
        >
            {{ $details }}
        </div>
    @endif
</div>
