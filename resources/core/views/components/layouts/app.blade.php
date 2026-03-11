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
        laraChatOpen: false,
        laraPrefillPrompt: null,

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
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('lara-chat-opened', { detail: { prompt: prompt } })));
        },
        toggleLaraChat(event) {
            if (this.isTypingTarget(event)) {
                return;
            }

            this.laraChatOpen = !this.laraChatOpen;
            if (this.laraChatOpen) {
                this.$nextTick(() => window.dispatchEvent(new CustomEvent('lara-chat-opened')));
            }
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
    @open-lara-chat.window="openLaraChat($event.detail?.prompt ?? null)"
    @close-lara-chat.window="laraChatOpen = false"
    @lara-execute-js.window="executeLaraJs($event.detail?.js ?? '')"
    @keydown.ctrl.k.window.prevent="toggleLaraChat($event)"
    @keydown.meta.k.window.prevent="toggleLaraChat($event)"
    @keydown.escape.window="laraChatOpen = false"
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

        <main class="flex-1 overflow-y-auto bg-surface-page px-1 py-2 sm:px-4 sm:py-1">
            {{ $slot }}
        </main>
    </div>

    @auth
        {{-- Lara chat panel (non-blocking — page remains interactive) --}}
        {{-- Desktop: floating overlay. Mobile: full-screen takeover. --}}
        <template x-if="laraChatOpen">
            <div>
                {{-- Desktop overlay --}}
                <div
                    x-show="laraChatOpen"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-2"
                    class="hidden sm:block fixed right-3 sm:right-4 bottom-8 z-50"
                >
                    <section class="w-[min(56rem,calc(100vw-2rem))] h-[min(80vh,46rem)] bg-surface-card border border-border-default rounded-2xl shadow-lg overflow-hidden">
                        <livewire:ai.lara-chat-overlay />
                    </section>
                </div>

                {{-- Mobile full-screen takeover --}}
                <div
                    x-show="laraChatOpen"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-4"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-4"
                    class="sm:hidden fixed inset-x-0 top-11 bottom-6 z-50 bg-surface-card"
                >
                    <div class="h-full flex flex-col">
                        {{-- Mobile chat header with close button --}}
                        <div class="flex items-center justify-between px-3 py-2 border-b border-border-default shrink-0">
                            <div class="flex items-center gap-2">
                                <x-ai.lara-identity compact :show-role="false" />
                                <span class="text-sm font-medium text-ink">{{ __('Lara') }}</span>
                            </div>
                            <button
                                type="button"
                                @click="laraChatOpen = false"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-sm text-muted hover:text-ink hover:bg-surface-subtle transition"
                                aria-label="{{ __('Close chat') }}"
                            >
                                <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                            </button>
                        </div>
                        <div class="flex-1 overflow-hidden">
                            <livewire:ai.lara-chat-overlay />
                        </div>
                    </div>
                </div>
            </div>
        </template>
    @endauth

    {{-- Status Bar --}}
    <x-layouts.status-bar />
</body>
</html>
