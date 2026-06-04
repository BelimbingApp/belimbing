const state = {
    wired: false,
    sidebarScroll: [],
}

const markAlpineReady = () => {
    globalThis.__blbAlpineReady = true
    document.documentElement.setAttribute('data-alpine-ready', '')
}

const applyClientHtmlState = () => {
    document.documentElement.classList.toggle('dark', localStorage.getItem('theme') === 'dark')
}

const applyDesktopSidebarWidth = () => {
    const shell = document.querySelector('[data-blb-sidebar-width-shell]')

    if (!shell) {
        return
    }

    const rail = (localStorage.getItem('sidebarRail') ?? '0') === '1'
    const expandedWidth = parseInt(localStorage.getItem('sidebarWidth')) || 224

    shell.style.width = `${rail ? 56 : expandedWidth}px`
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

const applyLaraChatShellState = () => {
    const chat = document.getElementById('lara-chat-instance')

    if (!chat) {
        return
    }

    const open = (localStorage.getItem('agent-chat-1-open') ?? '0') === '1'
    const mode = localStorage.getItem('agent-chat-1-mode') || 'overlay'
    const fullscreen = (localStorage.getItem('agent-chat-1-fullscreen') ?? '0') === '1'
    const dockWidth = parseInt(localStorage.getItem('agent-chat-1-dock-width')) || 448

    const dockTarget = document.querySelector('[x-ref="laraDockTarget"]')
    const overlayTarget = document.querySelector('[x-ref="laraOverlayTarget"]')
    const fullscreenTarget = document.querySelector('[x-ref="laraFullscreenTarget"]')
    const mobileTarget = document.querySelector('[x-ref="laraMobileTarget"]')

    const dockShell = dockTarget?.closest('aside')
    const overlayShell = overlayTarget?.closest('[x-show]')
    const fullscreenShell = fullscreenTarget?.closest('[x-show]')
    const mobileShell = mobileTarget?.closest('[x-show]')

    if (dockShell) {
        dockShell.style.width = `${dockWidth}px`
    }

    if (!open) {
        restoreLaraChatParkingStyle(chat)
        chat.style.display = 'none'
        setShellDisplay(dockShell, 'none')
        setShellDisplay(overlayShell, 'none')
        setShellDisplay(fullscreenShell, 'none')
        setShellDisplay(mobileShell, 'none')

        return
    }

    let target = overlayTarget
    let visibleShell = overlayShell
    let visibleDisplay = 'block'

    if (window.innerWidth < 640) {
        target = mobileTarget
        visibleShell = mobileShell
        visibleDisplay = 'block'
    } else if (fullscreen) {
        target = fullscreenTarget
        visibleShell = fullscreenShell
        visibleDisplay = 'block'
    } else if (mode === 'docked') {
        target = dockTarget
        visibleShell = dockShell
        visibleDisplay = 'flex'
    }

    for (const shell of [dockShell, overlayShell, fullscreenShell, mobileShell]) {
        if (shell && shell !== visibleShell) {
            setShellDisplay(shell, 'none')
        }
    }

    setShellDisplay(visibleShell, visibleDisplay)

    if (target && chat.parentNode !== target) {
        target.appendChild(chat)
    }

    restoreLaraChatParkingStyle(chat)
    chat.style.display = ''
}

const applyNavigateSwapShellState = () => {
    applyClientHtmlState()
    applyDesktopSidebarWidth()
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
            a.setAttribute('data-current', '')
        } else {
            a.removeAttribute('data-current')
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
    applyDesktopSidebarWidth,
    applyLaraChatShellState,
    prepareLaraChatForNavigate,
}

applyClientHtmlState()
