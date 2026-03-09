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
        sidebarOpen: false,
        sidebarCollapsed: localStorage.getItem('sidebarCollapsed') === '1',
        laraChatOpen: false,
        isTypingTarget(event) {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return false;
            }

            return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
        },
        openLaraChat() {
            this.laraChatOpen = true;
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('lara-chat-opened')));
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
    @toggle-sidebar.window="
        if (window.innerWidth >= 1024) {
            sidebarCollapsed = !sidebarCollapsed;
            localStorage.setItem('sidebarCollapsed', sidebarCollapsed ? '1' : '0');
        } else {
            sidebarOpen = !sidebarOpen;
        }
    "
    @open-lara-chat.window="openLaraChat()"
    @close-lara-chat.window="laraChatOpen = false"
    @lara-execute-js.window="executeLaraJs($event.detail?.js ?? '')"
    @keydown.ctrl.k.window.prevent="toggleLaraChat($event)"
    @keydown.meta.k.window.prevent="toggleLaraChat($event)"
    @keydown.escape.window="laraChatOpen = false"
    class="h-screen overflow-hidden bg-surface-page flex flex-col"
>
    {{-- Impersonation Banner --}}
    <x-layouts.impersonation-banner />

    {{-- Top Bar --}}
    <x-layouts.top-bar />

    {{-- Main Layout: Sidebar + Content --}}
    <div class="relative flex flex-1 overflow-hidden">
        {{-- Desktop Sidebar --}}
        <div class="hidden lg:block">
            <x-menu.sidebar :menuTree="$menuTree" :collapsed="true" x-show="sidebarCollapsed" />
            <x-menu.sidebar :menuTree="$menuTree" :collapsed="false" x-show="!sidebarCollapsed" />
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
            class="lg:hidden fixed top-11 bottom-6 left-0 z-40"
            style="display: none;"
        >
            <x-menu.sidebar :menuTree="$menuTree" :collapsed="false" />
        </div>

        <main class="flex-1 overflow-y-auto bg-surface-page p-3 sm:p-4">
            {{ $slot }}
        </main>
    </div>

    @auth
        {{-- Lara chat panel (non-blocking — page remains interactive) --}}
        <div
            x-show="laraChatOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-2"
            class="fixed right-3 sm:right-4 bottom-8 z-50"
            style="display: none;"
        >
            <section class="w-[min(56rem,calc(100vw-2rem))] h-[min(80vh,46rem)] bg-surface-card border border-border-default rounded-2xl shadow-lg overflow-hidden">
                <livewire:ai.lara-chat-overlay />
            </section>
        </div>
    @endauth

    {{-- Status Bar --}}
    <x-layouts.status-bar />
</body>
</html>
