<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>{{ isset($title) && $title ? $title . ' — ' . config('app.name') : config('app.name') }}</title>
    @include('partials.head')
</head>
<body
    x-data="{
        {{-- Mobile drawer --}}
        sidebarOpen: false,

        {{-- Desktop drag-resizable sidebar --}}
        sidebarWidth: parseInt(localStorage.getItem('sidebarWidth')) || 224,
        sidebarRail: (localStorage.getItem('sidebarRail') ?? '0') === '1',
        _lastExpandedWidth: parseInt(localStorage.getItem('sidebarWidth')) || 224,
        _dragging: false,

        {{-- Sidebar constants --}}
        RAIL_WIDTH: 56,
        MIN_WIDTH: 56,
        MAX_WIDTH: 288,
        COLLAPSE_THRESHOLD: 80,

        {{-- Lara chat --}}
        laraChatOpen: (localStorage.getItem('agent-chat-1-open') ?? '0') === '1',
        laraChatMode: localStorage.getItem('agent-chat-1-mode') || 'overlay',
        laraChatFullscreen: (localStorage.getItem('agent-chat-1-fullscreen') ?? '0') === '1',
        laraPrefillPrompt: null,

        {{-- Docked panel drag-resize --}}
        laraDockWidth: parseInt(localStorage.getItem('agent-chat-1-dock-width')) || 448,
        _laraDockDragging: false,
        DOCK_MIN: 320,
        DOCK_MAX: Math.floor(window.innerWidth * 0.6),

        {{-- Initialize sidebar from persisted state --}}
        initSidebar() {
            if (this.sidebarRail) {
                this.sidebarWidth = this.RAIL_WIDTH;
            }
        },

        {{-- Toggle between rail and last expanded width --}}
        toggleSidebar() {
            if (window.innerWidth >= 1024) {
                if (this.sidebarRail) {
                    this.sidebarRail = false;
                    this.sidebarWidth = this._lastExpandedWidth;
                } else {
                    this._lastExpandedWidth = this.sidebarWidth;
                    this.sidebarRail = true;
                    this.sidebarWidth = this.RAIL_WIDTH;
                }
                this._persistSidebar();
            } else {
                this.sidebarOpen = !this.sidebarOpen;
            }
        },

        {{-- Drag handle --}}
        startDrag(e) {
            this._dragging = true;
            const startX = e.clientX;
            const startWidth = this.sidebarWidth;
            document.documentElement.style.cursor = 'col-resize';
            document.documentElement.style.userSelect = 'none';

            const onMove = (e) => {
                const delta = e.clientX - startX;
                const newWidth = Math.max(this.MIN_WIDTH, Math.min(this.MAX_WIDTH, startWidth + delta));

                if (newWidth <= this.COLLAPSE_THRESHOLD) {
                    this.sidebarWidth = this.RAIL_WIDTH;
                    this.sidebarRail = true;
                } else {
                    this.sidebarWidth = newWidth;
                    this.sidebarRail = false;
                    this._lastExpandedWidth = newWidth;
                }
            };

            const onUp = () => {
                this._dragging = false;
                document.documentElement.style.cursor = '';
                document.documentElement.style.userSelect = '';
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                this._persistSidebar();
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },

        _persistSidebar() {
            localStorage.setItem('sidebarWidth', this._lastExpandedWidth);
            localStorage.setItem('sidebarRail', this.sidebarRail ? '1' : '0');
        },

        {{-- Dock panel drag --}}
        startDockDrag(e) {
            this._laraDockDragging = true;
            const startX = e.clientX;
            const startWidth = this.laraDockWidth;
            document.documentElement.style.cursor = 'col-resize';
            document.documentElement.style.userSelect = 'none';

            const onMove = (e) => {
                const delta = startX - e.clientX;
                this.laraDockWidth = Math.max(this.DOCK_MIN, Math.min(this.DOCK_MAX, startWidth + delta));
            };

            const onUp = () => {
                this._laraDockDragging = false;
                document.documentElement.style.cursor = '';
                document.documentElement.style.userSelect = '';
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                localStorage.setItem('agent-chat-1-dock-width', this.laraDockWidth);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },

        {{-- Lara chat helpers --}}
        isTypingTarget(event) {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return false;
            }

            return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
        },
        openLaraChat(prompt = null) {
            this.laraPrefillPrompt = prompt;
            this.laraChatOpen = true;
            localStorage.setItem('agent-chat-1-open', '1');
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('agent-chat-opened', { detail: { prompt: prompt } })));
        },
        closeLaraChat() {
            this.laraChatOpen = false;
            localStorage.setItem('agent-chat-1-open', '0');
        },
        toggleLaraChat(event) {
            if (this.isTypingTarget(event)) {
                return;
            }

            this.laraChatOpen = !this.laraChatOpen;
            localStorage.setItem('agent-chat-1-open', this.laraChatOpen ? '1' : '0');
            if (this.laraChatOpen) {
                this.$nextTick(() => window.dispatchEvent(new CustomEvent('agent-chat-opened')));
            }
        },
        toggleLaraChatMode() {
            if (this.laraChatFullscreen) {
                this.laraChatFullscreen = false;
                localStorage.setItem('agent-chat-1-fullscreen', '0');
            }

            this.laraChatMode = this.laraChatMode === 'overlay' ? 'docked' : 'overlay';
            localStorage.setItem('agent-chat-1-mode', this.laraChatMode);
        },
        toggleLaraFullscreen() {
            this.laraChatFullscreen = !this.laraChatFullscreen;
            localStorage.setItem('agent-chat-1-fullscreen', this.laraChatFullscreen ? '1' : '0');
        },
        executeLaraJs(js) {
            if (typeof js !== 'string' || js.trim() === '') {
                return;
            }

            try {
                new Function(js)(); // NOSONAR - intentional: executes Lara AI-injected JS in a sandboxed try/catch
            } catch (e) {
                console.error('[Lara] Action execution failed:', e); // NOSONAR - intentional error logging in catch block
            }
        }
    }"
    x-init="initSidebar()"
    @toggle-sidebar.window="toggleSidebar()"
    @open-agent-chat.window="openLaraChat($event.detail?.prompt ?? null)"
    @close-agent-chat.window="closeLaraChat()"
    @agent-chat-execute-js.window="executeLaraJs($event.detail?.js ?? '')"
    @toggle-agent-chat-mode.window="toggleLaraChatMode()"
    @toggle-agent-chat-fullscreen.window="toggleLaraFullscreen()"
    @keydown.ctrl.k.window.prevent="toggleLaraChat($event)"
    @keydown.meta.k.window.prevent="toggleLaraChat($event)"
    @keydown.ctrl.shift.k.window.prevent="toggleLaraChatMode()"
    @keydown.meta.shift.k.window.prevent="toggleLaraChatMode()"
    @keydown.ctrl.shift.f.window.prevent="toggleLaraFullscreen()"
    @keydown.meta.shift.f.window.prevent="toggleLaraFullscreen()"
    @keydown.escape.window="closeLaraChat()"
    class="h-screen overflow-hidden bg-surface-page flex flex-col"
>
    {{-- Top Bar --}}
    <x-layouts.top-bar />

    {{-- Main Layout: Sidebar + Content --}}
    <div class="relative flex flex-1 overflow-hidden">
        {{-- Desktop Sidebar (drag-resizable) --}}
        <div
            class="hidden lg:flex shrink-0 relative"
            :style="'width: ' + sidebarWidth + 'px'"
        >
            <x-menu.sidebar :menuTree="$menuTree" :menuItemsFlat="$menuItemsFlat ?? []" :pins="$pins ?? []" x-bind:data-rail="sidebarRail" />

            {{-- Drag handle --}}
            <div
                @mousedown.prevent="startDrag($event)"
                class="absolute top-0 bottom-0 right-0 w-1 cursor-col-resize z-20 group"
            >
                <div
                    class="w-full h-full transition-colors"
                    :class="_dragging ? 'bg-accent' : 'group-hover:bg-border-default'"
                ></div>
            </div>
        </div>

        {{-- Mobile Sidebar Backdrop --}}
        <div
            x-show="sidebarOpen"
            x-transition.opacity
            @click="sidebarOpen = false"
            class="lg:hidden fixed inset-0 z-30 bg-black/35"
            style="display: none;"
            aria-hidden="true"
        ></div>

        {{-- Mobile Sidebar Drawer --}}
        <div
            x-show="sidebarOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="-translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full"
            class="lg:hidden fixed top-11 bottom-6 left-0 z-40 w-56"
            style="display: none;"
        >
            <x-menu.sidebar :menuTree="$menuTree" :menuItemsFlat="$menuItemsFlat ?? []" :pins="$pins ?? []" />
        </div>

        <main class="relative flex-1 overflow-y-auto bg-surface-page px-1 py-2 sm:px-4 sm:py-1">
            {{ $slot }}

            @auth
                {{-- Fullscreen mode: take over the main content box (desktop only) --}}
                <div
                    x-show="laraChatOpen && laraChatFullscreen"
                    x-cloak
                    class="hidden sm:block absolute inset-0 z-50 bg-surface-card border border-border-default rounded-2xl overflow-hidden"
                >
                    <div class="h-full" x-ref="laraFullscreenTarget"></div>
                </div>
            @endauth
        </main>

        @auth
            {{-- Docked mode: right-side panel inside the layout flow (drag-resizable) --}}
            <aside
                x-show="laraChatOpen && !laraChatFullscreen && laraChatMode === 'docked'"
                x-cloak
                class="hidden sm:flex shrink-0 border-l border-border-default bg-surface-card overflow-hidden relative"
                :style="'width: ' + laraDockWidth + 'px'"
            >
                {{-- Drag handle (left edge) --}}
                <div
                    @mousedown.prevent="startDockDrag($event)"
                    class="absolute top-0 bottom-0 left-0 w-1 cursor-col-resize z-10 group"
                >
                    <div
                        class="w-full h-full transition-colors"
                        :class="_laraDockDragging ? 'bg-accent' : 'group-hover:bg-border-default'"
                    ></div>
                </div>
                {{-- Teleport target for docked mode --}}
                <div class="flex-1 min-w-0 h-full" x-ref="laraDockTarget"></div>
            </aside>
        @endauth
    </div>

    @auth
        {{-- Overlay mode: floating card (desktop only) --}}
        <div
            x-show="laraChatOpen && !laraChatFullscreen && laraChatMode === 'overlay'"
            x-cloak
            class="hidden sm:block fixed right-3 sm:right-4 bottom-8 z-50"
        >
            <section class="w-[min(56rem,calc(100vw-2rem))] h-[min(80vh,46rem)] bg-surface-card border border-border-default rounded-2xl shadow-lg overflow-hidden">
                {{-- Teleport target for overlay mode --}}
                <div class="h-full" x-ref="laraOverlayTarget"></div>
            </section>
        </div>

        {{-- Mobile: full-screen takeover --}}
        <div
            x-show="laraChatOpen"
            x-cloak
            class="sm:hidden fixed inset-x-0 top-11 bottom-6 z-50 bg-surface-card overflow-hidden"
        >
            {{-- Teleport target for mobile mode --}}
            <div class="h-full" x-ref="laraMobileTarget"></div>
        </div>

        {{-- Single Livewire instance — Alpine moves it into the active target --}}
        <div
            x-ref="laraChatInstance"
            x-effect="
                const el = $refs.laraChatInstance;
                if (!el) return;
                const isMobile = window.innerWidth < 640;
                let target;
                if (isMobile) {
                    target = $refs.laraMobileTarget;
                } else if (laraChatFullscreen) {
                    target = $refs.laraFullscreenTarget;
                } else if (laraChatMode === 'docked') {
                    target = $refs.laraDockTarget;
                } else {
                    target = $refs.laraOverlayTarget;
                }
                if (target && el.parentNode !== target) {
                    target.appendChild(el);
                }
            "
            class="h-full"
        >
            <livewire:ai.chat />
        </div>
    @endauth

    {{-- Status Bar --}}
    <x-layouts.status-bar />
</body>
</html>
