{{-- Lazy placeholder for the provider catalog island. Mirrors the card shape so
     the page does not jump when the real catalog streams in after first paint. --}}
<div>
    <x-ui.card>
        <div class="mb-3">
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Add a Provider') }}</span>
        </div>
        <div class="animate-pulse space-y-2" aria-hidden="true">
            <div class="h-9 w-full rounded-2xl bg-surface-subtle/60"></div>
            @for($i = 0; $i < 6; $i++)
                <div class="h-8 w-full rounded bg-surface-subtle/40"></div>
            @endfor
        </div>
        <p class="sr-only">{{ __('Loading provider catalog…') }}</p>
    </x-ui.card>
</div>
