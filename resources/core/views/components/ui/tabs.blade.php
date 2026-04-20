{{--
    Tabs: accessible page-level tab switcher with URL hash persistence.

    Props:
        tabs     — Array of tab definitions: [['id' => 'general', 'label' => 'General'], ...]
                   Each item must have 'id' and 'label'; optional 'icon' for a Heroicon name.
        default  — ID of the initially active tab (falls back to first tab)
        variant  — Visual style: 'underline' (default) or 'pill'
        size     — Density: 'md' (default) or 'sm'

    Usage:
        <x-ui.tabs :tabs="[
            ['id' => 'general', 'label' => __('General')],
            ['id' => 'addresses', 'label' => __('Addresses')],
            ['id' => 'contacts', 'label' => __('Contacts'), 'icon' => 'heroicon-o-user-group'],
        ]" default="general">
            <x-ui.tab id="general">
                General tab content...
            </x-ui.tab>
            <x-ui.tab id="addresses">
                Addresses tab content...
            </x-ui.tab>
            <x-ui.tab id="contacts">
                Contacts tab content...
            </x-ui.tab>
        </x-ui.tabs>

    ARIA: WAI-ARIA Tabs Pattern (tablist, tab, tabpanel roles, arrow key navigation).
    URL hash: Active tab is reflected in the URL hash (#tab-id) and restored on page load.
--}}
@props([
    'tabs' => [],
    'default' => null,
    'variant' => 'underline',
    'size' => 'md',
    'persistence' => 'hash', // 'hash' (default) or 'query'
    'queryKey' => 'tab',
])

@php
    use Illuminate\Support\Str;

    $tabsId = 'tabs-' . Str::random(8);
    $defaultTab = $default ?? ($tabs[0]['id'] ?? null);

    $sizeClasses = match($size) {
        'sm' => [
            'pill_list' => 'p-0.5 rounded-xl',
            'pill_tab' => 'px-3.5 py-1 rounded-lg text-sm',
            'underline_tab' => 'px-3.5 py-1 text-sm',
            'icon' => 'w-4 h-4',
            'panels' => 'mt-3',
        ],
        default => [
            'pill_list' => 'p-1 rounded-2xl',
            'pill_tab' => 'px-3.5 py-1.5 rounded-xl text-sm',
            'underline_tab' => 'px-3.5 py-2 text-sm',
            'icon' => 'w-4 h-4',
            'panels' => 'mt-4',
        ],
    };

    $variantClasses = match($variant) {
        'pill' => [
            'list' => 'flex gap-1 bg-surface-subtle '.$sizeClasses['pill_list'],
            'tab' => $sizeClasses['pill_tab'].' font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1',
            'active' => 'bg-surface-card text-ink shadow-sm',
            'inactive' => 'text-muted hover:text-ink',
        ],
        default => [
            'list' => 'flex gap-0 border-b border-border-default',
            'tab' => 'relative '.$sizeClasses['underline_tab'].' font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-accent focus:ring-inset',
            'active' => 'text-ink',
            'inactive' => 'text-muted hover:text-ink',
        ],
    };
@endphp

<div
    {{ $attributes->class([]) }}
    x-data="{
        activeTab: null,
        tabs: @js(collect($tabs)->pluck('id')->values()->all()),
        defaultTab: @js($defaultTab),
        prefix: '{{ $tabsId }}',
        persistence: @js($persistence),
        queryKey: @js($queryKey),

        init() {
            const url = new URL(window.location.href)
            const queryTab = url.searchParams.get(this.queryKey)
            const hash = window.location.hash?.slice(1)

            const initial = (this.persistence === 'query')
                ? queryTab
                : hash

            this.activeTab = (initial && this.tabs.includes(initial)) ? initial : this.defaultTab

            {{-- Listen for hash changes (e.g., back/forward navigation) --}}
            this._onHashChange = () => {
                const h = window.location.hash?.slice(1)
                if (h && this.tabs.includes(h)) {
                    this.activeTab = h
                }
            }
            if (this.persistence === 'hash') {
                window.addEventListener('hashchange', this._onHashChange)
            }

            this._onPopState = () => {
                const u = new URL(window.location.href)
                const qt = u.searchParams.get(this.queryKey)
                if (qt && this.tabs.includes(qt)) {
                    this.activeTab = qt
                }
            }
            if (this.persistence === 'query') {
                window.addEventListener('popstate', this._onPopState)
            }
        },

        destroy() {
            if (this.persistence === 'hash') {
                window.removeEventListener('hashchange', this._onHashChange)
            }
            if (this.persistence === 'query') {
                window.removeEventListener('popstate', this._onPopState)
            }
        },

        select(tabId) {
            this.activeTab = tabId
            if (this.persistence === 'query') {
                const url = new URL(window.location.href)
                url.searchParams.set(this.queryKey, tabId)
                url.hash = ''
                history.replaceState(null, '', url.toString())
            } else {
                history.replaceState(null, '', '#' + tabId)
            }
        },

        isActive(tabId) {
            return this.activeTab === tabId
        },

        tabId(id) {
            return this.prefix + '-tab-' + id
        },

        panelId(id) {
            return this.prefix + '-panel-' + id
        },

        {{-- Keyboard navigation: Arrow Left/Right to cycle, Home/End for first/last --}}
        onKeydown(event) {
            const idx = this.tabs.indexOf(this.activeTab)
            let newIdx = idx

            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                event.preventDefault()
                newIdx = (idx + 1) % this.tabs.length
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                event.preventDefault()
                newIdx = (idx - 1 + this.tabs.length) % this.tabs.length
            } else if (event.key === 'Home') {
                event.preventDefault()
                newIdx = 0
            } else if (event.key === 'End') {
                event.preventDefault()
                newIdx = this.tabs.length - 1
            } else {
                return
            }

            this.select(this.tabs[newIdx])

            {{-- Move focus to the newly activated tab button --}}
            this.$nextTick(() => {
                const tabEl = this.$refs.tablist?.querySelector('[data-tab-id=\'' + this.tabs[newIdx] + '\']')
                tabEl?.focus()
            })
        }
    }"
>
    {{-- Tab triggers --}}
    <div
        x-ref="tablist"
        role="tablist"
        @keydown="onKeydown($event)"
        class="{{ $variantClasses['list'] }}"
    >
        @foreach($tabs as $tab)
            <button
                type="button"
                role="tab"
                data-tab-id="{{ $tab['id'] }}"
                :id="tabId('{{ $tab['id'] }}')"
                :aria-selected="isActive('{{ $tab['id'] }}') ? 'true' : 'false'"
                :aria-controls="panelId('{{ $tab['id'] }}')"
                :tabindex="isActive('{{ $tab['id'] }}') ? '0' : '-1'"
                @click="select('{{ $tab['id'] }}')"
                class="{{ $variantClasses['tab'] }}"
                :class="isActive('{{ $tab['id'] }}') ? '{{ $variantClasses['active'] }}' : '{{ $variantClasses['inactive'] }}'"
            >
                @if(isset($tab['icon']))
                    <span class="inline-flex items-center gap-1.5">
                        <x-icon :name="$tab['icon']" class="{{ $sizeClasses['icon'] }}" />
                        <span>{{ $tab['label'] }}</span>
                    </span>
                @else
                    {{ $tab['label'] }}
                @endif

                {{-- Underline indicator (underline variant only) --}}
                @if($variant === 'underline')
                    <span
                        x-show="isActive('{{ $tab['id'] }}')"
                        class="absolute bottom-0 inset-x-0 h-0.5 bg-accent rounded-full"
                    ></span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Tab panels (rendered by <x-ui.tab> children) --}}
    <div class="{{ $sizeClasses['panels'] }}">
        {{ $slot }}
    </div>
</div>
