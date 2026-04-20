@props(['item', 'isActive', 'hasActiveChild', 'children'])

<li
    x-data="{ expanded: {{ $hasActiveChild ? 'true' : 'false' }} }"
    class="relative"
>
    @php
        $iconName = $item->icon ?? 'heroicon-o-squares-2x2';
        // Top-level container items (like Administration, Business Operations) get accent color in rail view
        $isTopLevelContainer = $item->isContainer() && $item->parent === null;
    @endphp

    @if($item->hasRoute())
        {{-- Link item: rail (icon-only) variant --}}
        <a
            x-show="sidebarRail"
            x-cloak
            href="{{ $item->route ? route($item->route) : $item->url }}"
            @if($item->route) wire:navigate @endif
            class="flex items-center justify-center w-full h-8 rounded-none transition {{ $isActive ? 'bg-surface-card text-ink' : 'text-link hover:bg-surface-subtle' }}"
            aria-label="{{ __($item->label) }}"
            title="{{ __($item->label) }}"
        >
            <x-icon :name="$iconName" class="w-[1.125rem] h-[1.125rem]" />
        </a>

        {{-- Link item: expanded variant --}}
        <div x-show="!sidebarRail" x-cloak class="group flex items-center w-full px-1 py-px text-sm rounded-none transition text-link {{ $isActive ? 'bg-surface-card text-ink font-medium' : 'hover:bg-surface-subtle font-normal' }}">
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
                href="{{ $item->route ? route($item->route) : $item->url }}"
                @if($item->route) wire:navigate @endif
                class="truncate flex-1"
            >{{ __($item->label) }}</a>

            {{-- Pin/unpin toggle (visible on hover) --}}
            <button
                type="button"
                @click.prevent="togglePin('{{ $item->id }}')"
                class="shrink-0 w-4 h-4 transition-opacity"
                :class="isPinnedByUrl('{{ $item->route ? route($item->route) : $item->url }}') ? 'text-accent opacity-100' : 'text-muted hover:text-ink opacity-0 group-hover:opacity-100'"
                :title="isPinnedByUrl('{{ $item->route ? route($item->route) : $item->url }}') ? '{{ __('Unpin') }}' : '{{ __('Pin to top') }}'"
                :aria-label="isPinnedByUrl('{{ $item->route ? route($item->route) : $item->url }}') ? '{{ __('Unpin :item', ['item' => $item->label]) }}' : '{{ __('Pin :item to top', ['item' => $item->label]) }}'"
            >
                <x-icon name="heroicon-o-pin" class="w-3.5 h-3.5" />
            </button>
        </div>
    @else
        {{-- Container item (no route): rail variant --}}
        <button
            x-show="sidebarRail"
            x-cloak
            type="button"
            @click="expanded = !expanded"
            class="flex items-center justify-center w-full h-8 rounded-none transition {{ $isTopLevelContainer ? 'text-accent' : ($hasActiveChild ? 'text-ink' : 'text-link hover:bg-surface-subtle') }}"
            aria-label="{{ __($item->label) }}"
            title="{{ __($item->label) }}"
        >
            <x-icon :name="$iconName" class="w-[1.125rem] h-[1.125rem]" />
        </button>

        {{-- Container item: expanded variant --}}
        <div
            x-show="!sidebarRail"
            x-cloak
            @click="expanded = !expanded"
            class="flex items-center gap-0.5 w-full px-1 py-px text-sm rounded-none cursor-pointer transition text-link {{ $hasActiveChild ? 'font-medium' : 'font-normal hover:bg-surface-subtle' }}"
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
            :class="sidebarRail ? 'ml-0 mt-0 space-y-0' : 'ml-3 mt-0 space-y-0'"
        >
            @foreach($children as $child)
                <x-menu.item
                    :item="$child['item']"
                    :isActive="$child['is_active']"
                    :hasActiveChild="$child['has_active_child']"
                    :children="$child['children']"
                />
            @endforeach
        </ul>
    @endif
</li>
