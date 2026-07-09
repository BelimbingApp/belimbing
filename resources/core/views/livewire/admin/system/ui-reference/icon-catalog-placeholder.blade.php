{{-- Lazy placeholder for the icon catalog island. Mirrors the card shape so
     the Foundations section does not jump when the full registry streams in. --}}
<div>
    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Icon Catalog')"
                component="<code>x-icon</code>"
            >
                {{ __('Every name registered in `icon.blade.php`, grouped by family. Click a tile to copy the `name` value for the `x-icon` component.') }}
            </x-ui.catalog-section>

            <div class="animate-pulse space-y-4" aria-hidden="true">
                <div class="h-9 w-full max-w-xs rounded-2xl bg-surface-subtle/60"></div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
                    @for ($i = 0; $i < 24; $i++)
                        <div class="h-16 rounded-xl bg-surface-subtle/40"></div>
                    @endfor
                </div>
            </div>
            <p class="sr-only">{{ __('Loading icon catalog…') }}</p>
        </div>
    </x-ui.card>
</div>
