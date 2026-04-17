<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>
@props(['menuTree', 'menuItemsFlat' => [], 'pins' => []])

@php
    $defaultRailPinIcon = 'heroicon-o-squares-2x2';

    $pinRailIconNames = collect($pins)
        ->pluck('icon')
        ->merge(collect($menuItemsFlat)->pluck('icon'))
        ->filter()
        ->unique()
        ->values();

    if (! $pinRailIconNames->contains($defaultRailPinIcon)) {
        $pinRailIconNames->push($defaultRailPinIcon);
    }

    $pinRailIconSvgs = $pinRailIconNames->mapWithKeys(
        static fn (string $name): array => [
            $name => \Illuminate\Support\Facades\Blade::render(
                '<x-icon :name="$name" class="w-[1.125rem] h-[1.125rem]" />',
                ['name' => $name],
            ),
        ],
    )->all();
@endphp

<aside
    {{ $attributes->class([
        'shrink-0 bg-surface-sidebar h-full w-full flex flex-col border-r border-border-default',
    ]) }}
    @toggle-page-pin.window="togglePagePin($event.detail)"
    @pins-synced.window="pins = $event.detail.pins"
    x-data="{
        pins: @js($pins),
        _dragIdx: null,
        _dropIdx: null,

        _normalizeUrl(url) {
            try {
                const u = new URL(url, window.location.origin);
                return u.pathname.replace(/\/+$/, '') || '/';
            } catch { return url; }
        },

        isPinnedByUrl(url) {
            const needle = this._normalizeUrl(url);
            return this.pins.some(p => this._normalizeUrl(p.url) === needle);
        },

        _acquireLock() {
            if (window.__pinBusy) return false;
            window.__pinBusy = true;
            return true;
        },
        _releaseLock() { window.__pinBusy = false; },
        _syncPins(pins) {
            this.pins = pins;
            window.dispatchEvent(new CustomEvent('pins-synced', { detail: { pins } }));
        },

        _apiHeaders() {
            return {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                'Accept': 'application/json',
            };
        },

        _toggleByUrl(label, url, icon) {
            if (!this._acquireLock()) return;

            const wasPinned = this.isPinnedByUrl(url);
            const prevPins = [...this.pins];

            if (wasPinned) {
                const needle = this._normalizeUrl(url);
                this.pins = this.pins.filter(p => this._normalizeUrl(p.url) !== needle);
            } else {
                this.pins.push({ id: null, label, url, icon: icon ?? null });
            }

            fetch('{{ route('pins.toggle') }}', {
                method: 'POST',
                headers: this._apiHeaders(),
                body: JSON.stringify({ label, url, icon: icon ?? null }),
            })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => { this._syncPins(data.pins); })
            .catch(() => { this._syncPins(prevPins); })
            .finally(() => { this._releaseLock(); });
        },

        togglePin(id) {
            const item = this.menuItemsFlat[id];
            if (!item) return;
            this._toggleByUrl(item.pinLabel, item.href, item.icon);
        },

        unpinFromSidebar(pin) {
            this._toggleByUrl(pin.label, pin.url, pin.icon);
        },

        togglePagePin(detail) {
            const { label, url, icon } = detail;
            this._toggleByUrl(label, url, icon);
        },

        reorderPins(orderedPins) {
            this.pins = orderedPins;

            fetch('{{ route('pins.reorder') }}', {
                method: 'POST',
                headers: this._apiHeaders(),
                body: JSON.stringify({
                    pins: orderedPins.map(pin => ({ id: pin.id })),
                }),
            })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => { this.pins = data.pins; })
            .catch(() => {
                {{-- Silently keep optimistic order on failure --}}
            });
        },

        {{-- Drag-reorder handlers --}}
        pinDragStart(idx, event) {
            this._dragIdx = idx;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', idx);
        },
        pinDragOver(idx, event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            this._dropIdx = idx;
        },
        pinDrop(idx) {
            if (this._dragIdx === null || this._dragIdx === idx) {
                this._dragIdx = null;
                this._dropIdx = null;
                return;
            }
            const reorderedPins = [...this.pins];
            const [moved] = reorderedPins.splice(this._dragIdx, 1);
            reorderedPins.splice(idx, 0, moved);
            this._dragIdx = null;
            this._dropIdx = null;
            this.reorderPins(reorderedPins);
        },
        pinDragEnd() {
            this._dragIdx = null;
            this._dropIdx = null;
        },

        defaultRailPinIcon: @js($defaultRailPinIcon),
        pinRailIconSvgs: @js($pinRailIconSvgs),
        pinRailIconName(pin) {
            if (pin?.icon) {
                return pin.icon;
            }
            const needle = this._normalizeUrl(pin.url);
            for (const id in this.menuItemsFlat) {
                const item = this.menuItemsFlat[id];
                if (item?.href && this._normalizeUrl(item.href) === needle) {
                    return item.icon ?? this.defaultRailPinIcon;
                }
            }
            return this.defaultRailPinIcon;
        },
        pinRailIconHtml(pin) {
            const name = this.pinRailIconName(pin);
            return this.pinRailIconSvgs[name] ?? this.pinRailIconSvgs[this.defaultRailPinIcon];
        },

        menuItemsFlat: @js($menuItemsFlat),
    }"
>
    {{-- Pinned section (above divider) --}}
    <template x-if="pins.length > 0">
        <div class="px-0.5 py-0.5 bg-surface-pinned rounded-sm">
            <div x-show="!sidebarRail" x-cloak class="px-1 pt-0.5 pb-px">
                <span class="text-[10px] uppercase tracking-wider text-muted font-medium select-none">{{ __('Pinned') }}</span>
            </div>
            <template x-for="(pin, idx) in pins" :key="'pinned-' + (pin.id ?? pin.url)">
                    <div
                        :draggable="!sidebarRail"
                        @dragstart="!sidebarRail && pinDragStart(idx, $event)"
                        @dragover.prevent="!sidebarRail && pinDragOver(idx, $event)"
                        @drop.prevent="!sidebarRail && pinDrop(idx)"
                        @dragend="pinDragEnd()"
                        :class="{
                            'opacity-40': _dragIdx === idx,
                            'border-t-2 border-accent': _dropIdx === idx && _dragIdx !== null && _dragIdx !== idx,
                        }"
                    >
                        {{-- Rail view: icon only (using the menu item's icon) --}}
                        <a
                            x-show="sidebarRail"
                            x-cloak
                            :href="pin.url"
                            wire:navigate
                            :title="pin.label"
                            :aria-label="pin.label"
                            class="flex items-center justify-center w-full h-8 rounded-none transition text-link hover:bg-surface-subtle"
                        >
                            <span class="inline-flex shrink-0 items-center justify-center" x-html="pinRailIconHtml(pin)"></span>
                            <span class="sr-only" x-text="pin.label"></span>
                        </a>

                        {{-- Expanded view: drag handle + label + unpin button --}}
                        <div
                            x-show="!sidebarRail"
                            x-cloak
                            class="group flex items-center w-full px-1 py-px text-sm rounded-none transition text-link hover:bg-surface-subtle font-normal"
                            :class="{ 'cursor-grab': !sidebarRail, 'cursor-grabbing': _dragIdx === idx }"
                        >
                            {{-- Drag grip (visible on hover) --}}
                            <span
                                class="text-[10px] shrink-0 w-3 text-center mr-0.5 text-muted opacity-0 group-hover:opacity-60 transition-opacity select-none"
                                aria-hidden="true"
                            >&#x2801;&#x2801;</span>
                            <a
                                :href="pin.url"
                                wire:navigate
                                :aria-label="pin.label"
                                class="truncate flex-1"
                                @click.stop
                            >
                                <span x-text="pin.label"></span>
                            </a>
                            <button
                                type="button"
                                @click.prevent.stop="unpinFromSidebar(pin)"
                                class="shrink-0 w-4 h-4 text-muted hover:text-ink opacity-0 group-hover:opacity-100 transition-opacity"
                                :title="'{{ __('Unpin') }}'"
                            >
                                <x-icon name="heroicon-o-pin" class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
            </template>

            {{-- Divider between pinned and main menu --}}
            <div class="mx-1 my-0.5 h-px bg-border-default/50"></div>
        </div>
    </template>

    {{-- Main menu tree (scrollable) --}}
    <nav class="flex-1 overflow-y-auto px-0.5 py-0.5" aria-label="{{ __('Main navigation') }}">
        <x-menu.tree :items="$menuTree" />
    </nav>

    {{-- Footer: User + Logout --}}
    <div class="px-0.5 py-0.5 border-t border-border-default space-y-0">
        {{-- Expanded view --}}
        <div
            x-show="!sidebarRail"
            x-cloak
            class="flex items-center gap-2 w-full px-1 py-0.5 rounded-none text-sm transition text-link font-normal hover:bg-surface-subtle"
        >
            <div class="w-7 h-7 rounded-full bg-accent text-accent-on flex items-center justify-center text-xs font-medium shrink-0">
                {{ auth()->user()->initials() }}
            </div>
            <div class="min-w-0 flex-1 text-left relative pr-7">
                <a href="{{ route('profile.edit') }}" wire:navigate class="block truncate text-ink hover:underline">
                    {{ auth()->user()->name }}
                </a>
                <div class="text-xs text-muted truncate">{{ auth()->user()->email }}</div>
                <form method="POST" action="{{ route('logout') }}" class="absolute top-1/2 right-0 -translate-y-1/2">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center w-6 h-6 rounded-sm text-muted hover:text-ink hover:bg-surface-subtle transition"
                        aria-label="{{ __('Log Out') }}"
                        title="{{ __('Log Out') }}"
                    >
                        <svg
                            viewBox="2 0 24 20"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                            transform="matrix(-1, 0, 0, 1, 0, 0)"
                            class="w-[1.44rem] h-[1.44rem]"
                            aria-hidden="true"
                        >
                            <path d="M12.9999 2C10.2385 2 7.99991 4.23858 7.99991 7C7.99991 7.55228 8.44762 8 8.99991 8C9.55219 8 9.99991 7.55228 9.99991 7C9.99991 5.34315 11.3431 4 12.9999 4H16.9999C18.6568 4 19.9999 5.34315 19.9999 7V17C19.9999 18.6569 18.6568 20 16.9999 20H12.9999C11.3431 20 9.99991 18.6569 9.99991 17C9.99991 16.4477 9.55219 16 8.99991 16C8.44762 16 7.99991 16.4477 7.99991 17C7.99991 19.7614 10.2385 22 12.9999 22H16.9999C19.7613 22 21.9999 19.7614 21.9999 17V7C21.9999 4.23858 19.7613 2 16.9999 2H12.9999Z" fill="currentColor" />
                            <path d="M13.9999 11C14.5522 11 14.9999 11.4477 14.9999 12C14.9999 12.5523 14.5522 13 13.9999 13V11Z" fill="currentColor" />
                            <path d="M5.71783 11C5.80685 10.8902 5.89214 10.7837 5.97282 10.682C6.21831 10.3723 6.42615 10.1004 6.57291 9.90549C6.64636 9.80795 6.70468 9.72946 6.74495 9.67492L6.79152 9.61162L6.804 9.59454L6.80842 9.58848C6.80846 9.58842 6.80892 9.58778 5.99991 9L6.80842 9.58848C7.13304 9.14167 7.0345 8.51561 6.58769 8.19098C6.14091 7.86637 5.51558 7.9654 5.19094 8.41215L5.18812 8.41602L5.17788 8.43002L5.13612 8.48679C5.09918 8.53682 5.04456 8.61033 4.97516 8.7025C4.83623 8.88702 4.63874 9.14542 4.40567 9.43937C3.93443 10.0337 3.33759 10.7481 2.7928 11.2929L2.08569 12L2.7928 12.7071C3.33759 13.2519 3.93443 13.9663 4.40567 14.5606C4.63874 14.8546 4.83623 15.113 4.97516 15.2975C5.04456 15.3897 5.09918 15.4632 5.13612 15.5132L5.17788 15.57L5.18812 15.584L5.19045 15.5872C5.51509 16.0339 6.14091 16.1336 6.58769 15.809C7.0345 15.4844 7.13355 14.859 6.80892 14.4122L5.99991 15C6.80892 14.4122 6.80897 14.4123 6.80892 14.4122L6.804 14.4055L6.79152 14.3884L6.74495 14.3251C6.70468 14.2705 6.64636 14.1921 6.57291 14.0945C6.42615 13.8996 6.21831 13.6277 5.97282 13.318C5.89214 13.2163 5.80685 13.1098 5.71783 13H13.9999V11H5.71783Z" fill="currentColor" />
                        </svg>
                    </button>
                </form>
            </div>
        </div>

        {{-- Rail (icon-only) view --}}
        <div
            x-show="sidebarRail"
            x-cloak
            class="flex items-center justify-center w-full px-1 py-0.5 rounded-none text-sm transition text-link font-normal hover:bg-surface-subtle"
        >
            <div class="w-7 h-7 rounded-full bg-accent text-accent-on flex items-center justify-center text-xs font-medium shrink-0" title="{{ auth()->user()->name }}">
                {{ auth()->user()->initials() }}
            </div>
        </div>
    </div>
</aside>