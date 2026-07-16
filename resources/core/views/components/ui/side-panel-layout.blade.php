@props([
    'storageKey' => 'sidePanelWidth',
    'defaultWidth' => 240,
    'minWidth' => 200,
    'maxWidth' => 360,
    'contentClass' => '',
    'mobilePanel' => null,
    'mobilePanelLabel' => null,
])

@php
    $resolvedPanelLabel = $mobilePanelLabel ?: __('Side panel');
    $resolvedMobilePanelLabel = $mobilePanelLabel ?: __('Open side panel');
    $mobilePanelId = 'side-panel-mobile-'.\Illuminate\Support\Str::slug($storageKey);
@endphp

<div
    x-data="{
        panelWidth: parseInt(localStorage.getItem(@js($storageKey))) || {{ (int) $defaultWidth }},
        _panelDragging: false,
        mobilePanelOpen: false,
        PANEL_MIN: {{ (int) $minWidth }},
        PANEL_MAX: {{ (int) $maxWidth }},
        openMobilePanel() {
            this.mobilePanelOpen = true;
            this.$nextTick(() => this.$refs.mobilePanelClose?.focus());
        },
        closeMobilePanel(restoreFocus = true) {
            this.mobilePanelOpen = false;

            if (restoreFocus) {
                this.$nextTick(() => this.$refs.mobilePanelTrigger?.focus());
            }
        },
        startPanelDrag(e) {
            this._panelDragging = true;
            const startX = e.clientX;
            const startWidth = this.panelWidth;
            document.documentElement.style.cursor = 'col-resize';
            document.documentElement.style.userSelect = 'none';

            const onMove = (moveEvent) => {
                this.panelWidth = Math.max(this.PANEL_MIN, Math.min(this.PANEL_MAX, startWidth + (moveEvent.clientX - startX)));
            };

            const onUp = () => {
                this._panelDragging = false;
                document.documentElement.style.cursor = '';
                document.documentElement.style.userSelect = '';
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                localStorage.setItem(@js($storageKey), this.panelWidth);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },
    }"
    @keydown.escape.window="if (mobilePanelOpen) closeMobilePanel()"
    @resize.window="if (window.innerWidth >= 1024) mobilePanelOpen = false"
    class="space-y-section-gap lg:-mx-1 lg:-my-2"
>
    @if ($mobilePanel)
        <div
            class="sticky top-0 z-20 -mx-1 bg-surface-page px-1 py-1 sm:-mx-4 sm:px-4 lg:hidden"
            data-side-panel-mobile-trigger-shell
        >
            <x-ui.button
                type="button"
                variant="control"
                size="sm"
                x-ref="mobilePanelTrigger"
                @click="openMobilePanel()"
                ::aria-expanded="mobilePanelOpen.toString()"
                aria-controls="{{ $mobilePanelId }}"
                data-side-panel-mobile-trigger
            >
                <x-icon name="heroicon-o-bars-3" class="h-4 w-4" />
                {{ $resolvedMobilePanelLabel }}
            </x-ui.button>

            <div
                x-show="mobilePanelOpen"
                x-cloak
                x-transition.opacity.duration.150ms
                @click="closeMobilePanel()"
                class="fixed inset-x-0 top-11 bottom-6 z-30 bg-black/35 motion-reduce:transition-none"
                aria-hidden="true"
                style="display: none;"
            ></div>

            <aside
                id="{{ $mobilePanelId }}"
                x-show="mobilePanelOpen"
                x-cloak
                x-transition:enter="transform transition ease-out duration-200 motion-reduce:duration-0"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transform transition ease-in duration-150 motion-reduce:duration-0"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="fixed top-11 bottom-6 left-0 z-40 flex w-80 max-w-[calc(100vw-3rem)] flex-col overflow-hidden border-r border-border-default bg-surface-sidebar shadow-lg"
                aria-label="{{ $resolvedPanelLabel }}"
                data-side-panel-mobile
                style="display: none;"
            >
                {{ $mobilePanel }}
            </aside>
        </div>
    @endif

    <div class="lg:flex lg:gap-0">
        <div
            class="hidden lg:flex lg:shrink-0 lg:relative"
            :style="'width: ' + panelWidth + 'px'"
        >
            <aside class="flex w-full flex-col overflow-hidden border-r border-border-default bg-surface-sidebar" aria-label="{{ $resolvedPanelLabel }}">
                {{ $panel }}
            </aside>

            <div
                @mousedown.prevent="startPanelDrag($event)"
                class="absolute top-0 bottom-0 right-0 z-20 w-1 cursor-col-resize group"
            >
                <div
                    class="h-full w-full transition-colors"
                    :class="_panelDragging ? 'bg-accent' : 'group-hover:bg-border-default'"
                ></div>
            </div>
        </div>

        <div @class(['min-w-0 flex-1 px-1 py-2 sm:px-0 sm:py-0 lg:px-4 lg:py-2', $contentClass])>
            {{ $slot }}
        </div>
    </div>
</div>
