{{-- showRail=false (the mobile drawer, which never collapses to the icon
     rail) skips the rail-variant markup entirely — it halved the drawer's
     copy of every menu item. --}}
@props(['item', 'isActive', 'hasActiveChild', 'children', 'showRail' => true])

<li
    x-data="{ expanded: {{ $hasActiveChild ? 'true' : 'false' }} }"
    class="group/menuitem relative"
>
    @php
        $iconName = $item->icon ?? 'heroicon-o-squares-2x2';
        // Top-level container items (like Administration, Business Operations) get accent color in rail view
        $isTopLevelContainer = $item->isContainer() && $item->parent === null;
        // Debug-only tooltip: source attribution helps identify whether an item
        // came from BLB core or an extension when something looks wrong.
        $tooltip = __($item->label);
        if (config('app.debug') && $item->sourceFile) {
            $tooltip .= "\n[".$item->id.($item->sourceModule ? ' · '.$item->sourceModule : '').']'
                ."\n".$item->sourceFile;
        }

        $href = $item->href();
    @endphp

    @if($item->hasRoute())
        {{-- Link item: rail (icon-only) variant --}}
        @if($showRail)
            <a
                x-show="rail"
                x-cloak
                href="{{ $href }}"
                @if($item->route) wire:navigate @endif
                class="flex items-center justify-center w-full h-8 rounded-none transition text-link hover:bg-surface-subtle data-[current]:bg-surface-card data-[current]:text-ink"
                aria-label="{{ __($item->label) }}"
                title="{{ $tooltip }}"
            >
                <x-icon :name="$iconName" class="w-[1.125rem] h-[1.125rem]" />
            </a>
        @endif

        {{-- Link item: expanded variant. Active state is driven client-side by
             wire:current (it sets data-current on the matching link), so the
             persisted sidebar's highlight tracks navigation without a re-render. --}}
        <div
            x-show="!rail"
            x-cloak
            class="group flex items-center w-full px-1 py-px text-sm rounded-none transition text-link hover:bg-surface-subtle has-[[data-current]]:bg-surface-card has-[[data-current]]:text-ink"
        >
            @if(count($children) > 0)
                <span
                    @click.prevent="expanded = !expanded"
                    class="text-[12px] shrink-0 text-link w-3 text-center cursor-pointer mr-0.5"
                    aria-hidden="true"
                >
                    <span x-show="!expanded">&#x2BC8;</span>
                    <span x-show="expanded">&#x2BC6;</span>
                </span>
            @else
                <span
                    class="text-[12px] shrink-0 w-3 text-center mr-0.5"
                    aria-hidden="true"
                >&#8199;</span>
            @endif

            <a
                href="{{ $href }}"
                @if($item->route) wire:navigate @endif
                class="truncate flex-1 font-normal text-link data-[current]:text-accent"
                title="{{ $tooltip }}"
            >{{ __($item->label) }}</a>

            {{-- Pin/unpin toggle (visible on hover) --}}
            <button
                type="button"
                @click.prevent="togglePin('{{ $item->id }}')"
                class="shrink-0 w-4 h-4 transition-opacity"
                :class="isPinnedByUrl('{{ $href }}') ? 'text-accent opacity-100' : 'text-muted hover:text-ink opacity-0 group-hover:opacity-100'"
                :title="isPinnedByUrl('{{ $href }}') ? '{{ __('Unpin') }}' : '{{ __('Pin to top') }}'"
                :aria-label="isPinnedByUrl('{{ $href }}') ? '{{ __('Unpin :item', ['item' => $item->label]) }}' : '{{ __('Pin :item to top', ['item' => $item->label]) }}'"
            >
                <x-icon name="heroicon-o-pin" class="w-3.5 h-3.5" />
            </button>
        </div>
    @else
        {{-- Container item (no route): rail variant --}}
        @if($showRail)
            <button
                x-show="rail"
                x-cloak
                type="button"
                @click="expanded = !expanded"
                class="flex items-center justify-center w-full h-8 rounded-none transition {{ $isTopLevelContainer ? 'text-accent' : 'text-link hover:bg-surface-subtle group-has-[[data-current]]/menuitem:text-ink' }}"
                aria-label="{{ __($item->label) }}"
                title="{{ $tooltip }}"
            >
                <x-icon :name="$iconName" class="w-[1.125rem] h-[1.125rem]" />
            </button>
        @endif

        {{-- Container item: expanded variant --}}
        <div
            x-show="!rail"
            x-cloak
            @click="expanded = !expanded"
            class="flex items-center gap-0.5 w-full px-1 py-px text-sm rounded-none cursor-pointer transition hover:bg-surface-subtle font-normal text-link [&:has(+ul_[data-current])]:text-accent"
            title="{{ $tooltip }}"
        >
            <span class="text-[12px] shrink-0 text-link w-3 text-center" aria-hidden="true">
                <span x-show="!expanded">&#x2BC8;</span>
                <span x-show="expanded">&#x2BC6;</span>
            </span>

            <span class="truncate">{{ __($item->label) }}</span>
        </div>
    @endif

    {{-- Children (recursive) --}}
    @if(count($children) > 0)
        <ul
            x-show="expanded"
            x-transition
            :class="rail ? 'ml-0 mt-0 space-y-0' : 'ml-3 mt-0 space-y-0'"
        >
            @foreach($children as $child)
                <x-menu.item
                    :item="$child['item']"
                    :isActive="$child['is_active']"
                    :hasActiveChild="$child['has_active_child']"
                    :children="$child['children']"
                    :showRail="$showRail"
                />
            @endforeach
        </ul>
    @endif
</li>
