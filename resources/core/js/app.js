// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

// Mark the runtime once Alpine has finished its first boot. Livewire navigate
// replaces <html> attributes from the fetched page, so the JS flag is the source
// of truth; the data attribute is only a DOM hint for debugging/CSS hooks.
{
    const markAlpineReady = () => {
        globalThis.__blbAlpineReady = true
        document.documentElement.setAttribute('data-alpine-ready', '')
    }

    document.addEventListener('alpine:initialized', markAlpineReady)
    document.addEventListener('livewire:navigated', markAlpineReady)
}

globalThis.blbApplyClientHtmlState = () => {
    document.documentElement.classList.toggle('dark', localStorage.getItem('theme') === 'dark')
}

globalThis.blbApplyDesktopSidebarWidth = () => {
    const shell = document.querySelector('[data-blb-sidebar-width-shell]')

    if (!shell) {
        return
    }

    const rail = (localStorage.getItem('sidebarRail') ?? '0') === '1'
    const expandedWidth = parseInt(localStorage.getItem('sidebarWidth')) || 224

    shell.style.width = `${rail ? 56 : expandedWidth}px`
}

globalThis.blbPrepareLaraChatForNavigate = () => {
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

const blbRestoreLaraChatParkingStyle = (chat) => {
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

const blbSetShellDisplay = (shell, display) => {
    if (!shell) {
        return
    }

    shell.removeAttribute('x-cloak')
    shell.style.display = display
}

globalThis.blbApplyLaraChatShellState = () => {
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
        blbRestoreLaraChatParkingStyle(chat)
        chat.style.display = 'none'
        blbSetShellDisplay(dockShell, 'none')
        blbSetShellDisplay(overlayShell, 'none')
        blbSetShellDisplay(fullscreenShell, 'none')
        blbSetShellDisplay(mobileShell, 'none')

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
            blbSetShellDisplay(shell, 'none')
        }
    }

    blbSetShellDisplay(visibleShell, visibleDisplay)

    if (target && chat.parentNode !== target) {
        target.appendChild(chat)
    }

    blbRestoreLaraChatParkingStyle(chat)
    chat.style.display = ''
}

globalThis.blbApplyNavigateSwapShellState = () => {
    globalThis.blbApplyClientHtmlState()
    globalThis.blbApplyDesktopSidebarWidth()
    globalThis.blbApplyLaraChatShellState()
}

globalThis.blbApplyClientHtmlState()

const blbDateTimeOptions = (format, includeSeconds = false) => {
    const timeOptions = includeSeconds
        ? { hour: '2-digit', minute: '2-digit', second: '2-digit' }
        : { hour: '2-digit', minute: '2-digit' }

    return {
        datetime: {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            ...timeOptions,
        },
        date: {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        },
        time: timeOptions,
    }[format] ?? {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        ...timeOptions,
    }
}

const blbResolveFormatter = ({
    locale,
    format,
    mode = 'local',
    companyTimezone = null,
    includeSeconds = false,
}) => {
    const options = blbDateTimeOptions(format, includeSeconds)

    if (mode === 'company' && companyTimezone) {
        options.timeZone = companyTimezone
    }

    if (mode === 'utc') {
        options.timeZone = 'UTC'
    }

    return new Intl.DateTimeFormat(locale || undefined, options)
}

globalThis.blbFormatDateTimeElement = (element, config = {}) => {
    if (!element) {
        return
    }

    const rawText = config.rawText
        ?? element.dataset.rawText
        ?? element.textContent
        ?? ''

    if (!element.dataset.rawText) {
        element.dataset.rawText = rawText
    }

    const mode = config.mode ?? element.dataset.timezoneMode ?? 'local'

    if (mode === 'utc') {
        element.textContent = rawText

        return
    }

    const iso = config.iso ?? element.getAttribute('datetime')

    if (!iso) {
        element.textContent = rawText

        return
    }

    const date = new Date(iso)

    if (Number.isNaN(date.getTime())) {
        element.textContent = rawText

        return
    }

    const formatter = blbResolveFormatter({
        locale: config.locale ?? element.dataset.locale,
        format: config.format ?? element.dataset.format ?? 'datetime',
        mode,
        companyTimezone: config.companyTimezone ?? element.dataset.companyTimezone ?? null,
        includeSeconds: config.includeSeconds ?? element.dataset.includeSeconds === 'true',
    })

    element.textContent = formatter.format(date)
}

globalThis.blbMountDateTimeElement = (element, getConfig = () => ({})) => {
    if (!element) {
        return
    }

    let observer = null

    const apply = () => {
        observer?.disconnect()
        globalThis.blbFormatDateTimeElement(element, getConfig() || {})
        observer?.observe(element, {
            characterData: true,
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['datetime', 'data-raw-text', 'data-format', 'data-locale', 'data-company-timezone'],
        })
    }

    element._blbDateTimeUnmount?.()

    observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'characterData' || mutation.type === 'childList' || mutation.type === 'attributes') {
                apply()
                break
            }
        }
    })

    element._blbDateTimeUnmount = () => observer.disconnect()

    apply()
}

globalThis.blbFormatDateTimeMatches = (text, config = {}) => {
    const source = typeof text === 'string' ? text : String(text ?? '')
    const mode = config.mode ?? 'local'

    if (mode === 'utc') {
        return source
    }

    const formatter = blbResolveFormatter({
        locale: config.locale,
        format: config.format ?? 'datetime',
        mode,
        companyTimezone: config.companyTimezone ?? null,
        includeSeconds: config.includeSeconds ?? false,
    })

    return source.replaceAll(/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(?:Z|[+-]\d{2}:\d{2})?/g, (match) => {
        try {
            const hasTimezone = /(?:Z|[+-]\d{2}:\d{2})$/.test(match)
            let iso = match
            if (!hasTimezone) {
                if (match.includes('T')) {
                    iso = `${match}Z`
                } else {
                    iso = `${match.replace(' ', 'T')}Z`
                }
            }
            const date = new Date(iso)

            if (Number.isNaN(date.getTime())) {
                return match
            }

            return formatter.format(date)
        } catch {
            return match
        }
    })
}

// Shift builder — interactive clock dial + day strip + grace sliders
import './shift-builder.js'

// Alpine.js - only initialize if not already loaded by Livewire
if (!globalThis.Alpine) {
    try {
        const module = await import('alpinejs')

        globalThis.Alpine = module.default
        globalThis.Alpine.start()
    } catch (error) {
        console.error('Failed to load Alpine.js.', error)
    }
}
