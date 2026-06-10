const storedInt = (key, fallback) => Number.parseInt(localStorage.getItem(key)) || fallback

// Rail (icon-only) state lives in a global Alpine store, NOT in blbAppShell's
// x-data. The desktop sidebar is an x-persist region whose <aside>/<li> carry
// their own x-data; on wire:navigate the body's blbAppShell is rebuilt as a new
// component while the persisted sidebar keeps a scope chain pointing at the old,
// dead one. A store is a page-lifetime singleton untouched by the morph, so the
// live body and the persisted sidebar always read/write the same `rail` value —
// otherwise the width updates (a directive on the persist node, re-evaluated by
// Livewire) but the sidebar's `x-show` stays frozen on the dead scope.
document.addEventListener('alpine:init', () => {
    globalThis.Alpine.store('shell', {
        rail: (localStorage.getItem('sidebarRail') ?? '0') === '1',
    })
})

globalThis.blbAppShell = ({ laraActivated = false } = {}) => ({
    sidebarOpen: false,
    sidebarWidth: storedInt('sidebarWidth', 224),
    _lastExpandedWidth: storedInt('sidebarWidth', 224),
    _dragging: false,

    RAIL_WIDTH: 56,
    MIN_WIDTH: 56,
    MAX_WIDTH: 288,
    COLLAPSE_THRESHOLD: 80,

    laraActivated,
    laraChatOpen: (localStorage.getItem('agent-chat-1-open') ?? '0') === '1',
    laraChatMode: localStorage.getItem('agent-chat-1-mode') || 'overlay',
    laraChatFullscreen: (localStorage.getItem('agent-chat-1-fullscreen') ?? '0') === '1',
    laraPrefillPrompt: null,
    laraBusy: false,

    laraDockWidth: storedInt('agent-chat-1-dock-width', 448),
    _laraDockDragging: false,
    DOCK_MIN: 320,
    DOCK_MAX: Math.floor(globalThis.innerWidth * 0.6),

    init() {
        this.initSidebar()
        // $watch handlers cover in-page chat toggles (open/mode/fullscreen). The
        // initial + post-navigate placement is owned by shell-navigation's
        // applyLaraChatShellState() (which runs pre-paint at wire()/onSwap and
        // also forces the mode-shell containers visible), so no $nextTick teleport
        // is needed here — it was only ever a guarded no-op. Verified by probe:
        // the chat is placed solely by applyLaraChatShellState on load and navigate.
        this.$watch('laraChatOpen', () => this.teleportLaraChat())
        this.$watch('laraChatMode', () => this.teleportLaraChat())
        this.$watch('laraChatFullscreen', () => this.teleportLaraChat())
        globalThis.blbShellNavigation?.wire()
    },

    initSidebar() {
        if (this.$store.shell.rail) {
            this.sidebarWidth = this.RAIL_WIDTH
        }

        if (!this.laraActivated) {
            this.laraChatOpen = false
            this.laraChatFullscreen = false
            localStorage.removeItem('agent-chat-1-open')
            localStorage.removeItem('agent-chat-1-fullscreen')
        }
    },

    toggleSidebar() {
        if (globalThis.innerWidth >= 1024) {
            if (this.$store.shell.rail) {
                this.$store.shell.rail = false
                this.sidebarWidth = this._lastExpandedWidth
            } else {
                this._lastExpandedWidth = this.sidebarWidth
                this.$store.shell.rail = true
                this.sidebarWidth = this.RAIL_WIDTH
            }

            this._persistSidebar()
        } else {
            this.sidebarOpen = !this.sidebarOpen
        }
    },

    startDrag(event) {
        this._dragging = true
        const startX = event.clientX
        const startWidth = this.sidebarWidth
        document.documentElement.style.cursor = 'col-resize'
        document.documentElement.style.userSelect = 'none'

        const onMove = (moveEvent) => {
            const delta = moveEvent.clientX - startX
            const newWidth = Math.max(this.MIN_WIDTH, Math.min(this.MAX_WIDTH, startWidth + delta))

            if (newWidth <= this.COLLAPSE_THRESHOLD) {
                this.sidebarWidth = this.RAIL_WIDTH
                this.$store.shell.rail = true
            } else {
                this.sidebarWidth = newWidth
                this.$store.shell.rail = false
                this._lastExpandedWidth = newWidth
            }
        }

        const onUp = () => {
            this._dragging = false
            document.documentElement.style.cursor = ''
            document.documentElement.style.userSelect = ''
            document.removeEventListener('mousemove', onMove)
            document.removeEventListener('mouseup', onUp)
            this._persistSidebar()
        }

        document.addEventListener('mousemove', onMove)
        document.addEventListener('mouseup', onUp)
    },

    _persistSidebar() {
        localStorage.setItem('sidebarWidth', this._lastExpandedWidth)
        localStorage.setItem('sidebarRail', this.$store.shell.rail ? '1' : '0')
    },

    startDockDrag(event) {
        this._laraDockDragging = true
        const startX = event.clientX
        const startWidth = this.laraDockWidth
        document.documentElement.style.cursor = 'col-resize'
        document.documentElement.style.userSelect = 'none'

        const onMove = (moveEvent) => {
            const delta = startX - moveEvent.clientX
            this.laraDockWidth = Math.max(this.DOCK_MIN, Math.min(this.DOCK_MAX, startWidth + delta))
        }

        const onUp = () => {
            this._laraDockDragging = false
            document.documentElement.style.cursor = ''
            document.documentElement.style.userSelect = ''
            document.removeEventListener('mousemove', onMove)
            document.removeEventListener('mouseup', onUp)
            localStorage.setItem('agent-chat-1-dock-width', this.laraDockWidth)
        }

        document.addEventListener('mousemove', onMove)
        document.addEventListener('mouseup', onUp)
    },

    isTypingTarget(event) {
        const target = event.target

        if (!(target instanceof HTMLElement)) {
            return false
        }

        return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
    },

    openLaraChat(prompt = null) {
        if (!this.laraActivated) {
            return
        }

        this.laraPrefillPrompt = prompt
        this.laraChatOpen = true
        localStorage.setItem('agent-chat-1-open', '1')
        this.$nextTick(() => globalThis.dispatchEvent(new CustomEvent('agent-chat-opened', { detail: { prompt } })))
    },

    closeLaraChat() {
        this.laraChatOpen = false
        localStorage.setItem('agent-chat-1-open', '0')
    },

    toggleLaraChat(event) {
        if (!this.laraActivated) {
            return
        }

        if (this.isTypingTarget(event)) {
            return
        }

        this.laraChatOpen = !this.laraChatOpen
        localStorage.setItem('agent-chat-1-open', this.laraChatOpen ? '1' : '0')

        if (this.laraChatOpen) {
            this.$nextTick(() => globalThis.dispatchEvent(new CustomEvent('agent-chat-opened')))
        }
    },

    toggleLaraChatMode() {
        if (!this.laraActivated) {
            return
        }

        if (this.laraChatFullscreen) {
            this.laraChatFullscreen = false
            localStorage.setItem('agent-chat-1-fullscreen', '0')
        }

        this.laraChatMode = this.laraChatMode === 'overlay' ? 'docked' : 'overlay'
        localStorage.setItem('agent-chat-1-mode', this.laraChatMode)
    },

    toggleLaraFullscreen() {
        if (!this.laraActivated) {
            return
        }

        this.laraChatFullscreen = !this.laraChatFullscreen
        localStorage.setItem('agent-chat-1-fullscreen', this.laraChatFullscreen ? '1' : '0')
    },

    executeLaraJs(js) {
        if (typeof js !== 'string' || js.trim() === '') {
            return
        }

        try {
            new Function(js)() // NOSONAR - intentional: executes Lara AI-injected JS in a sandboxed try/catch
        } catch (error) {
            console.error('[Lara] Action execution failed:', error) // NOSONAR - intentional error logging in catch block
        }
    },

    teleportLaraChat() {
        const element = document.getElementById('lara-chat-instance')

        if (!element) {
            return
        }

        // Mode→target decision is shared with shell-navigation's pre-paint repair
        // so the two placement paths can't drift. Here (in-page toggle) the mode
        // shells are shown/hidden reactively by x-show; we only move the chat.
        const targetRef = globalThis.blbShellNavigation?.resolveLaraChatTargetRef({
            open: this.laraChatOpen,
            mode: this.laraChatMode,
            fullscreen: this.laraChatFullscreen,
        })
        const target = targetRef ? this.$refs[targetRef] : null

        if (target) {
            if (element.parentNode !== target) {
                target.appendChild(element)
            }

            element.style.display = ''
        } else {
            element.style.display = 'none'
        }
    },
})
