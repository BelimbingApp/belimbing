{{-- Lazy placeholder shared by all dashboard widgets. Mirrors the card shape
     so the grid does not jump when the real widget streams in. --}}
<div>
    <x-ui.card>
        <div class="animate-pulse space-y-3 motion-reduce:animate-none" aria-hidden="true">
            <div class="h-3 w-24 rounded bg-surface-subtle/60"></div>
            <div class="h-8 w-32 rounded bg-surface-subtle/40"></div>
            <div class="h-3 w-40 rounded bg-surface-subtle/40"></div>
        </div>
        <p class="sr-only">{{ __('Loading widget…') }}</p>
    </x-ui.card>
</div>
