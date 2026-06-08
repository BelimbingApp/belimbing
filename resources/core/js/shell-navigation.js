const state = {
    wired: false,
    sidebarScroll: [],
}

const markAlpineReady = () => {
    globalThis.__blbAlpineReady = true
    document.documentElement.dataset.alpineReady = ''
}

const applyClientHtmlState = () => {
    document.documentElement.classList.toggle('dark', localStorage.getItem('theme') === 'dark')
}

const prepareLaraChatForNavigate = () => {
    const chat = document.getElementById('lara-chat-instance')
    const home = document.getElementById('lara-chat-home')

    if (!chat || !home || chat.parentNode === home) {
        return
    }

    const rect = chat.getBoundingClientRect()
    const visible = getComputedStyle(chat).display !== 'none' && rect.width > 0 && rect.height > 0

    if (!visible) {
        home.appendChild(chat)

        return
    }

    chat.dataset.blbPreNavigateStyle = chat.getAttribute('style') ?? ''
    Object.assign(chat.style, {
        position: 'fixed',
        left: `${rect.left}px`,
        top: `${rect.top}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
        zIndex: '60',
        display: '',
    })

    home.appendChild(chat)
}

const restoreLaraChatParkingStyle = (chat) => {
    if (!chat?.hasAttribute('data-blb-pre-navigate-style')) {
        return
    }

    const previousStyle = chat.dataset.blbPreNavigateStyle
    delete chat.dataset.blbPreNavigateStyle

    if (previousStyle === '') {
        chat.removeAttribute('style')
    } else {
        chat.setAttribute('style', previousStyle)
    }

    chat.style.display = ''
}

const setShellDisplay = (shell, display) => {
    if (!shell) {
        return
    }

    shell.removeAttribute('x-cloak')
    shell.style.display = display
}

// CSS display each mode shell uses when visible (dock is a flex column).
const LARA_SHELL_DISPLAY = {
    laraDockTarget: 'flex',
    laraOverlayTarget: 'block',
    laraFullscreenTarget: 'block',
    laraMobileTarget: 'block',
}

// Single source of truth for which mode target the chat belongs in, given its
// open/mode/fullscreen state and the viewport — null means hidden. Shared by the
// pre-paint navigate repair (applyLaraChatShellState, below) and the in-page
// Alpine toggle handler (teleportLaraChat in shell-layout.js) so the mode
// decision can never drift between the two placement paths.
const resolveLaraChatTargetRef = ({ open, mode, fullscreen }) => {
    if (!open) {
        return null
    }

    if (globalThis.innerWidth < 640) {
        return 'laraMobileTarget'
    }

    if (fullscreen) {
        return 'laraFullscreenTarget'
    }

    if (mode === 'docked') {
        return 'laraDockTarget'
    }

    return 'laraOverlayTarget'
}

const applyLaraChatShellState = () => {
    const chat = document.getElementById('lara-chat-instance')

    if (!chat) {
        return
    }

    const open = (localStorage.getItem('agent-chat-1-open') ?? '0') === '1'
    const mode = localStorage.getItem('agent-chat-1-mode') || 'overlay'
    const fullscreen = (localStorage.getItem('agent-chat-1-fullscreen') ?? '0') === '1'
    const dockWidth = Number.parseInt(localStorage.getItem('agent-chat-1-dock-width')) || 448

    const targetRef = resolveLaraChatTargetRef({ open, mode, fullscreen })

    const targets = {
        laraDockTarget: document.querySelector('[x-ref="laraDockTarget"]'),
        laraOverlayTarget: document.querySelector('[x-ref="laraOverlayTarget"]'),
        laraFullscreenTarget: document.querySelector('[x-ref="laraFullscreenTarget"]'),
        laraMobileTarget: document.querySelector('[x-ref="laraMobileTarget"]'),
    }

    // The dock target lives inside an <aside>; the others inside an [x-show] box.
    const shells = {}
    for (const ref of Object.keys(targets)) {
        shells[ref] = ref === 'laraDockTarget'
            ? targets[ref]?.closest('aside')
            : targets[ref]?.closest('[x-show]')
    }

    if (shells.laraDockTarget) {
        shells.laraDockTarget.style.width = `${dockWidth}px`
    }

    const visibleShell = targetRef ? shells[targetRef] : null

    for (const shell of Object.values(shells)) {
        if (shell && shell !== visibleShell) {
            setShellDisplay(shell, 'none')
        }
    }

    if (!targetRef) {
        restoreLaraChatParkingStyle(chat)
        chat.style.display = 'none'

        return
    }

    setShellDisplay(visibleShell, LARA_SHELL_DISPLAY[targetRef])

    if (chat.parentNode !== targets[targetRef]) {
        targets[targetRef].appendChild(chat)
    }

    restoreLaraChatParkingStyle(chat)
    chat.style.display = ''
}

const applyNavigateSwapShellState = () => {
    applyClientHtmlState()
    // Desktop sidebar width is no longer reapplied here: the sidebar column is an
    // x-persist region, so its live element (with its current width) is carried
    // across wire:navigate untouched. See app.blade.php [data-blb-sidebar-width-shell].
    applyLaraChatShellState()
}

const markActiveMenu = () => {
    const norm = (path) => (path.replace(/\/+$/, '') || '/')
    const cur = norm(location.pathname)
    const links = [...document.querySelectorAll('aside nav a[href]')]
    let best = -1

    const scored = links.map((a) => {
        const lp = norm(new URL(a.getAttribute('href'), location.href).pathname)
        let score = -1

        if (lp === cur) {
            score = 100000 + lp.length
        } else if (cur === lp || cur.startsWith(`${lp}/`)) {
            score = lp.length
        }

        if (score > best) {
            best = score
        }

        return { a, score }
    })

    scored.forEach(({ a, score }) => {
        if (best >= 0 && score === best) {
            a.dataset.current = ''
        } else {
            delete a.dataset.current
        }
    })
}

const expandActiveGroups = () => {
    document.querySelectorAll('aside [data-current]').forEach((link) => {
        let li = link.closest('li')

        while (li) {
            const data = globalThis.Alpine?.$data(li)

            if (data && 'expanded' in data) {
                data.expanded = true
            }

            li = li.parentElement?.closest('li')
        }
    })
}

const rememberSidebarScroll = () => {
    state.sidebarScroll = [...document.querySelectorAll('aside nav')].map((nav) => nav.scrollTop)
}

const restoreSidebarScroll = () => {
    document.querySelectorAll('aside nav').forEach((nav, i) => {
        if (state.sidebarScroll[i] != null) {
            nav.scrollTop = state.sidebarScroll[i]
        }
    })
}

const refreshPersistedChrome = () => {
    applyNavigateSwapShellState()
    markActiveMenu()
    expandActiveGroups()
    restoreSidebarScroll()
}

const stripPersistedChromeCloak = (mutations) => {
    if (!globalThis.__blbAlpineReady) {
        return
    }

    for (const mutation of mutations) {
        const element = mutation.target

        if (element.nodeType === 1 && element.hasAttribute('x-cloak') && !element.closest('main')) {
            element.removeAttribute('x-cloak')
        }
    }
}

const wire = () => {
    if (state.wired) {
        return
    }

    state.wired = true

    new MutationObserver(stripPersistedChromeCloak).observe(document.documentElement, {
        subtree: true,
        attributes: true,
        attributeFilter: ['x-cloak'],
    })

    document.addEventListener('livewire:navigating', (event) => {
        prepareLaraChatForNavigate()
        rememberSidebarScroll()
        event.detail?.onSwap?.(() => refreshPersistedChrome())
    })

    document.addEventListener('livewire:navigated', () => {
        refreshPersistedChrome()
    })

    applyNavigateSwapShellState()
    markActiveMenu()
}

document.addEventListener('alpine:initialized', markAlpineReady)
document.addEventListener('livewire:navigated', markAlpineReady)

globalThis.blbShellNavigation = {
    wire,
    refreshPersistedChrome,
    applyNavigateSwapShellState,
    applyClientHtmlState,
    applyLaraChatShellState,
    resolveLaraChatTargetRef,
    prepareLaraChatForNavigate,
}

applyClientHtmlState()
