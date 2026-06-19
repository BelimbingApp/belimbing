@props([
    'closeAction',
    'labelledby',
    'storageKey' => 'blb:inspector-drawer:width',
    'defaultWidth' => 768,
    'minWidth' => 400,
])

@php
    $defaultWidth = (int) $defaultWidth;
    $minWidth = (int) $minWidth;
@endphp

<div
    x-data="{
        open: @entangle($attributes->wire('model')),
        panelWidth: {{ $defaultWidth }},
        isMobile: false,
        isResizing: false,
        PANEL_DEFAULT: {{ $defaultWidth }},
        PANEL_MIN: {{ $minWidth }},
        init() {
            this.panelWidth = this.clampWidth(this.readStoredWidth());
            this.syncResponsiveState();
        },
        readStoredWidth() {
            try {
                const saved = Number.parseInt(localStorage.getItem(@js($storageKey)) || '', 10);

                return Number.isFinite(saved) ? saved : this.PANEL_DEFAULT;
            } catch (error) {
                return this.PANEL_DEFAULT;
            }
        },
        syncResponsiveState() {
            this.isMobile = window.matchMedia('(max-width: 639px)').matches;
            this.panelWidth = this.clampWidth(this.panelWidth);
        },
        clampWidth(width) {
            return Math.max(this.PANEL_MIN, Math.min(this.maxWidth(), Number.parseInt(width, 10) || this.PANEL_DEFAULT));
        },
        maxWidth() {
            return Math.max(this.PANEL_MIN, window.innerWidth);
        },
        panelStyle() {
            if (this.isMobile) {
                return 'width: 100vw; max-width: 100vw;';
            }

            return `width: ${this.panelWidth}px; max-width: 100vw;`;
        },
        setPanelWidth(width, persist = false) {
            this.panelWidth = this.clampWidth(width);

            if (persist) {
                this.persistWidth();
            }
        },
        resizeBy(delta) {
            this.setPanelWidth(this.panelWidth + delta, true);
        },
        toggleFullWidth() {
            const isFullWidth = this.panelWidth >= this.maxWidth() - 8;
            this.setPanelWidth(isFullWidth ? this.PANEL_DEFAULT : this.maxWidth(), true);
        },
        persistWidth() {
            if (this.isMobile) {
                return;
            }

            try {
                localStorage.setItem(@js($storageKey), String(this.panelWidth));
            } catch (error) {
                return;
            }

            window.dispatchEvent(new CustomEvent('blb-inspector-width', {
                detail: {
                    storageKey: @js($storageKey),
                    width: this.panelWidth,
                },
            }));
        },
        syncExternalWidth(event) {
            if (! event.detail || event.detail.storageKey !== @js($storageKey)) {
                return;
            }

            this.setPanelWidth(event.detail.width);
        },
        startResize(event) {
            if (this.isMobile) {
                return;
            }

            this.isResizing = true;
            document.documentElement.style.cursor = 'ew-resize';
            document.documentElement.style.userSelect = 'none';

            const onMove = (moveEvent) => {
                this.setPanelWidth(window.innerWidth - moveEvent.clientX);
            };

            const stopResize = () => {
                this.isResizing = false;
                document.documentElement.style.cursor = '';
                document.documentElement.style.userSelect = '';
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', stopResize);
                document.removeEventListener('pointercancel', stopResize);
                this.persistWidth();
            };

            onMove(event);
            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup', stopResize, { once: true });
            document.addEventListener('pointercancel', stopResize, { once: true });
        },
    }"
    x-show="open"
    x-cloak
    @resize.window="syncResponsiveState()"
    @blb-inspector-width.window="syncExternalWidth($event)"
    @keydown.escape.window="$wire.{{ $closeAction }}()"
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'pointer-events-auto fixed inset-0 z-50 overflow-hidden sm:pointer-events-none']) }}
    style="display: none;"
>
    <div
        x-show="open"
        x-transition.opacity.duration.150ms
        @click="$wire.{{ $closeAction }}()"
        class="absolute inset-0 bg-black/50 sm:hidden"
    ></div>

    <div class="pointer-events-none absolute inset-y-0 right-0 flex max-w-full">
        <section
            x-show="open"
            x-transition:enter="transform transition ease-out duration-200 motion-reduce:duration-0"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform transition ease-in duration-150 motion-reduce:duration-0"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            @click.stop
            :style="panelStyle()"
            class="pointer-events-auto relative flex h-full w-screen flex-col border-l border-border-default bg-surface-card shadow-xl sm:w-auto"
            role="dialog"
            aria-modal="false"
            aria-labelledby="{{ $labelledby }}"
        >
            <div
                @pointerdown.prevent="startResize($event)"
                @dblclick.prevent="toggleFullWidth()"
                @keydown.left.prevent="resizeBy($event.shiftKey ? 120 : 40)"
                @keydown.right.prevent="resizeBy($event.shiftKey ? -120 : -40)"
                @keydown.home.prevent="setPanelWidth(maxWidth(), true)"
                @keydown.end.prevent="setPanelWidth(PANEL_MIN, true)"
                class="group absolute inset-y-0 left-0 z-20 -ml-2 hidden w-4 cursor-ew-resize items-stretch justify-center focus:outline-none focus:ring-2 focus:ring-accent sm:flex"
                role="separator"
                tabindex="0"
                aria-label="{{ __('Resize inspector panel') }}"
                aria-orientation="vertical"
                :aria-valuemin="PANEL_MIN"
                :aria-valuemax="maxWidth()"
                :aria-valuenow="panelWidth"
            >
                <span
                    aria-hidden="true"
                    class="my-4 w-1 rounded-full transition-colors"
                    :class="isResizing ? 'bg-accent' : 'bg-transparent group-hover:bg-border-default group-focus:bg-border-default'"
                ></span>
            </div>

            {{ $slot }}
        </section>
    </div>
</div>
