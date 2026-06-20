@props(['title' => null])

@php
    $laraActivated = auth()->check()
        && \App\Modules\Core\Employee\Models\Employee::laraActivationState() === true;

    // On wire:navigate the persisted chrome is kept client-side and the freshly
    // rendered copy is discarded — so re-rendering the sidebar + bars is wasted
    // work (~40ms/navigation). Skip rendering their content for navigate requests;
    // the empty @persist markers make the client keep its existing chrome.
    $skipShellRender = request()->hasHeader('X-Livewire-Navigate');
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
    {{-- Top Bar — persisted region: its content is page-independent (logo, company,
         user, timezone, locale), so keep the same DOM across wire:navigate instead
         of tearing down and rebuilding the full-width bar (which read as a flash). --}}
    @persist('top-bar')
        @unless($skipShellRender)
            <x-layouts.top-bar />
        @endunless
    @endpersist

    {{-- Main Layout: Sidebar + Content --}}
    <div class="relative flex flex-1 overflow-hidden">
        {{-- Desktop Sidebar (drag-resizable).
             Sidebar persisted region: x-persist on the width column keeps the column DOM
             (wrapper width + menu tree) across wire:navigate, so only the main
             body re-renders. The live element is carried over intact, so its
             already-applied width survives — no morph reset, no client-side width
             reapplication needed. (x-persist is exactly what @persist expands to;
             asset injection is already forced by the other @persist regions.)
             The :style width binding is a REACTIVE effect, which Livewire re-runs
             on the persisted node after morph, so it keeps tracking the live body
             scope. Active state tracks the URL via wire:current; pins sync
             client-side.
             NOTE: the drag handle is deliberately OUTSIDE the persist (see below)
             — event listeners are NOT re-attached on the persisted node after a
             morph, so an in-persist @mousedown would mutate the stale, discarded
             body scope and break drag-resize after the first navigation. --}}
        <div
            data-blb-sidebar-width-shell
            x-persist="sidebar-desktop"
            class="hidden lg:flex shrink-0 relative"
            :style="'width: ' + sidebarWidth + 'px'"
        >
            @unless($skipShellRender)
                <x-menu.sidebar :menuTree="$menuTree" :menuItemsFlat="$menuItemsFlat ?? []" :pins="$pins ?? []" />
            @endunless
        </div>

        {{-- Drag handle — kept OUTSIDE the persisted column on purpose so its
             @mousedown listener re-initializes against the fresh body scope on
             every navigation. Positioned at the column's right edge via a
             reactive :style (re-evaluated each navigate). --}}
        <div
            data-blb-sidebar-drag-handle
            @mousedown.prevent="startDrag($event)"
            class="hidden lg:block absolute top-0 bottom-0 w-1 cursor-col-resize z-20 group"
            :style="'left: ' + (sidebarWidth - 2) + 'px'"
        >
            <div
                class="w-full h-full transition-colors"
                :class="_dragging ? 'bg-accent' : 'group-hover:bg-border-default'"
            ></div>
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
                @unless($skipShellRender)
                    {{-- Mobile drawer has a fixed width with room for labels — never
                         the icon-only rail, even if the desktop column was collapsed. --}}
                    <x-menu.sidebar :menuTree="$menuTree" :menuItemsFlat="$menuItemsFlat ?? []" :pins="$pins ?? []" :honorRail="false" />
                @endunless
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

    {{-- Status Bar — persisted region (page-independent: locale, environment,
         impersonation/license notices). Same reasoning as the top bar. --}}
    @persist('status-bar')
        @unless($skipShellRender)
            <x-layouts.status-bar />
        @endunless
    @endpersist

    {{-- Global notification outlet: catches `notify` events from the
         InteractsWithNotifications trait. Page-independent and self-contained, so
         it is persisted across wire:navigate like the other chrome regions. --}}
    @persist('notification-hub')
        <x-ui.notification-hub />
    @endpersist
</body>
</html>
