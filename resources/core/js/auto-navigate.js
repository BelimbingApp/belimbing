// Route plain same-origin links through Livewire's SPA navigation.
//
// The app shell (top bar, sidebar, status bar) is persisted across
// wire:navigate and its server-side render is skipped for navigate requests —
// but only links that opt in get that fast path. Chrome links do; content
// links across pages rarely remember to, and every missed one pays a full
// shell rebuild (~1.3 MB of layout HTML plus menu/authz work) per click.
// Intercepting here makes the fast path the default for every current and
// future view, including extensions, without touching each anchor.
//
// Opt out with `data-no-navigate` on the anchor or any ancestor.

// Responses that are files, not app pages — navigating into them would swap
// the document body with binary/CSV content instead of downloading it.
const FILE_LIKE_PATH = /\.(?:csv|pdf|zip|gz|tar|xlsx?|docx?|pptx?|png|jpe?g|gif|svg|webp|avif|ics|txt|xml|json|mp[34]|webm)$|\/(?:download|export)(?:\/|$)/i

// Auth pages use the guest layout: leaving the app chrome is fine, but coming
// BACK in must be a full load so the chrome exists to persist (see the menu
// view composer invariant). Keep the whole boundary on full page loads.
const AUTH_PATH = /^\/(?:login|logout|register|password|two-factor|email\/verify)(?:\/|$)/

const shouldAutoNavigate = (event, anchor) => {
    if (event.defaultPrevented || event.button !== 0) {
        return false
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false
    }

    // Livewire already drives these; intercepting would double-navigate.
    if (anchor.hasAttribute('wire:navigate') || anchor.hasAttribute('wire:navigate.hover')) {
        return false
    }

    if (anchor.closest('[data-no-navigate]')) {
        return false
    }

    if (anchor.hasAttribute('download')) {
        return false
    }

    const target = anchor.getAttribute('target')

    if (target && target !== '_self') {
        return false
    }

    const href = anchor.getAttribute('href')

    if (!href || href.startsWith('#')) {
        return false
    }

    let url

    try {
        url = new URL(href, location.href)
    } catch {
        return false
    }

    if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) {
        return false
    }

    // Same-page fragment jumps stay native.
    if (url.hash && url.pathname === location.pathname && url.search === location.search) {
        return false
    }

    return !FILE_LIKE_PATH.test(url.pathname) && !AUTH_PATH.test(url.pathname)
}

// Bubble phase, registered on the document: inner handlers (Alpine
// @click.prevent, Livewire wire:click) run first and their preventDefault is
// respected via event.defaultPrevented above.
document.addEventListener('click', (event) => {
    const anchor = event.target.closest?.('a[href]')

    if (!anchor || !globalThis.Livewire?.navigate) {
        return
    }

    if (!shouldAutoNavigate(event, anchor)) {
        return
    }

    event.preventDefault()
    globalThis.Livewire.navigate(anchor.href)
})
