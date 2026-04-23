<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'storageKey' => 'sidePanelWidth',
    'defaultWidth' => 240,
    'minWidth' => 200,
    'maxWidth' => 360,
    'contentClass' => '',
    'mobilePanel' => null,
])

<div
    x-data="{
        panelWidth: parseInt(localStorage.getItem(@js($storageKey))) || {{ (int) $defaultWidth }},
        _panelDragging: false,
        PANEL_MIN: {{ (int) $minWidth }},
        PANEL_MAX: {{ (int) $maxWidth }},
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
    class="space-y-section-gap lg:-mx-1 lg:-my-2"
>
    @if ($mobilePanel)
        <div class="lg:hidden">
            {{ $mobilePanel }}
        </div>
    @endif

    <div class="lg:flex lg:gap-0">
        <div
            class="hidden lg:flex lg:shrink-0 lg:relative"
            :style="'width: ' + panelWidth + 'px'"
        >
            <aside class="flex w-full flex-col overflow-hidden border-r border-border-default bg-surface-sidebar">
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

