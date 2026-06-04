@props(['title' => null])

@php
    $laraActivated = auth()->check()
        && \App\Modules\Core\Employee\Models\Employee::laraActivationState() === true;
@endphp

<!DOCTYPE html>
<html lang="{{ app(\App\Base\Locale\Contracts\LocaleContext::class)->forIntl() }}">
<head>
    <title>{{ isset($title) && $title ? $title . ' — ' . config('app.name') : config('app.name') }}</title>
    @include('partials.head')
</head>
<body
    x-data="window.blbAppShell({ laraActivated: @js($laraActivated) })"
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
    @agent-chat-busy.window="laraBusy = true"
    @agent-chat-idle.window="laraBusy = false"
    @keydown.escape.window="closeLaraChat()"
    class="h-screen overflow-hidden bg-surface-page flex flex-col"
>
    {{-- Top Bar — persisted island: its content is page-independent (logo, company,
         user, timezone, locale), so keep the same DOM across wire:navigate instead
         of tearing down and rebuilding the full-width bar (which read as a flash). --}}
    @persist('top-bar')
        <x-layouts.top-bar />
    @endpersist

    {{-- Main Layout: Sidebar + Content --}}
    <div class="relative flex flex-1 overflow-hidden">
        {{-- Desktop Sidebar (drag-resizable) --}}
        <div
            data-blb-sidebar-width-shell
            class="hidden lg:flex shrink-0 relative"
            :style="'width: ' + sidebarWidth + 'px'"
        >
            {{-- Sidebar island: @persist keeps the menu DOM across wire:navigate
                 so only the main body re-renders. Active state tracks the URL via
                 wire:current; pins already sync client-side. --}}
            @persist('sidebar-desktop')
                <x-menu.sidebar :menuTree="$menuTree" :menuItemsFlat="$menuItemsFlat ?? []" :pins="$pins ?? []" x-bind:data-rail="sidebarRail" />
            @endpersist

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
            @persist('sidebar-mobile')
                <x-menu.sidebar :menuTree="$menuTree" :menuItemsFlat="$menuItemsFlat ?? []" :pins="$pins ?? []" />
            @endpersist
        </div>

        <main class="relative flex-1 overflow-y-auto bg-surface-page px-1 py-2 sm:px-4 sm:py-1">
            {{ $slot }}

            @if ($laraActivated)
                {{-- Fullscreen mode: take over the main content box (desktop only) --}}
                <div
                    x-show="laraChatOpen && laraChatFullscreen"
                    x-cloak
                    class="hidden sm:block absolute inset-0 z-50 bg-surface-card border border-border-default rounded-2xl overflow-hidden"
                >
                    <div class="h-full" x-ref="laraFullscreenTarget"></div>
                </div>
            @endif
        </main>

        @if ($laraActivated)
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
        @endif
    </div>

    @if ($laraActivated)
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

        {{-- Single persisted Lara chat instance. The shell Alpine data moves
             #lara-chat-instance into the active mode target; @persist plus
             parking it back into #lara-chat-home on livewire:navigating keeps the
             live instance (and its state) alive across wire:navigate. --}}
        @persist('lara-chat')
        <div id="lara-chat-home" style="display: contents;">
            <div id="lara-chat-instance" class="h-full" style="display: none;">
                <livewire:ai.chat />
            </div>
        </div>
        @endpersist
    @endif

    {{-- Status Bar — persisted island (page-independent: locale, environment,
         impersonation/license notices). Same reasoning as the top bar. --}}
    @persist('status-bar')
        <x-layouts.status-bar />
    @endpersist
</body>
</html>
