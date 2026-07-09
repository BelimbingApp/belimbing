@php
use App\Base\System\Livewire\UiReference\IconCatalog;

/** @var IconCatalog $this */
@endphp

<div>
    <x-ui.card x-data="{ query: '' }">
        <div class="space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <x-ui.catalog-section
                    :title="__('Icon Catalog')"
                    component="<code>x-icon</code>"
                >
                    {{ __('Every name registered in `icon.blade.php`, grouped by family. Click a tile to copy the `name` value for the `x-icon` component.') }}
                </x-ui.catalog-section>

                <x-ui.search-input
                    class="sm:w-64"
                    placeholder="{{ __('Filter icons…') }}"
                    x-model.debounce.100ms="query"
                />
            </div>

            @php $allIconNames = collect($iconCatalog)->pluck('icons')->flatten()->all(); @endphp

            <p
                x-show="query !== '' && ! @js($allIconNames).some((n) => n.includes(query.toLowerCase()))"
                x-cloak
                class="text-sm text-muted"
            >
                <span x-text="'{{ __('No icons match') }} “' + query + '”'"></span>
            </p>

            <div class="space-y-5">
                @foreach ($iconCatalog as $group)
                    <div
                        x-data="{ groupIcons: @js($group['icons']) }"
                        x-show="query === '' || groupIcons.some((n) => n.includes(query.toLowerCase()))"
                    >
                        <div class="mb-2 flex items-baseline justify-between">
                            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __($group['label']) }}</h3>
                            <span class="text-xs text-muted">{{ __($group['note']) }} · {{ count($group['icons']) }}</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8">
                            @foreach ($group['icons'] as $iconName)
                                <button
                                    type="button"
                                    x-data="{ copied: false }"
                                    x-show="@js($iconName).includes(query.toLowerCase())"
                                    @click="navigator.clipboard?.writeText(@js($iconName)); copied = true; setTimeout(() => copied = false, 1200)"
                                    class="group relative flex flex-col items-center gap-1.5 rounded-xl border border-border-default bg-surface-card p-2 text-center transition-colors hover:border-accent hover:bg-surface-subtle focus:outline-none focus:ring-2 focus:ring-accent/40"
                                    title="{{ $iconName }}"
                                    :aria-label="copied ? @js(__('Copied')) : @js($iconName)"
                                >
                                    <x-icon :name="$iconName" class="h-5 w-5 text-ink group-hover:text-accent" />
                                    <span class="w-full truncate text-[10px] text-muted">{{ $iconName }}</span>
                                    <span x-show="copied" x-cloak class="absolute -top-7 left-1/2 -translate-x-1/2 whitespace-nowrap rounded border border-border-default bg-surface-card px-1.5 py-0.5 text-[11px] text-status-success shadow-sm">{{ __('Copied!') }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </x-ui.card>
</div>
