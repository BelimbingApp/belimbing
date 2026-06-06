const storedInt = (key, fallback) => parseInt(localStorage.getItem(key)) || fallback

globalThis.blbAppShell = ({ laraActivated = false } = {}) => ({
    sidebarOpen: false,
    sidebarWidth: storedInt('sidebarWidth', 224),
    sidebarRail: (localStorage.getItem('sidebarRail') ?? '0') === '1',
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
    DOCK_MAX: Math.floor(window.innerWidth * 0.6),

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
        if (this.sidebarRail) {
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
        if (window.innerWidth >= 1024) {
            if (this.sidebarRail) {
                this.sidebarRail = false
                this.sidebarWidth = this._lastExpandedWidth
            } else {
                this._lastExpandedWidth = this.sidebarWidth
                this.sidebarRail = true
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
                this.sidebarRail = true
            } else {
                this.sidebarWidth = newWidth
                this.sidebarRail = false
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
        localStorage.setItem('sidebarRail', this.sidebarRail ? '1' : '0')
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
        this.$nextTick(() => window.dispatchEvent(new CustomEvent('agent-chat-opened', { detail: { prompt } })))
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
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('agent-chat-opened')))
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

        const isMobile = window.innerWidth < 640
        let target = null

        if (this.laraChatOpen) {
            if (isMobile) {
                target = this.$refs.laraMobileTarget
            } else if (this.laraChatFullscreen) {
                target = this.$refs.laraFullscreenTarget
            } else if (this.laraChatMode === 'docked') {
                target = this.$refs.laraDockTarget
            } else {
                target = this.$refs.laraOverlayTarget
            }
        }

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
