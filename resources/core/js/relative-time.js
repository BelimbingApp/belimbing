/**
 * Live relative timestamps.
 *
 * A server-rendered "3 minutes ago" is correct for exactly one instant and then
 * silently rots: an admin page left open reported a commit as "25 minutes ago"
 * fourteen hours after it was rendered. The server text stays as the no-JS
 * fallback; this re-derives it from the machine-readable `datetime` attribute
 * and keeps re-deriving it while the page is open.
 */

const TICK_MS = 30_000

const UNITS = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
    ['second', 1],
]

const blbResolveRelativeFormatter = (locale) => {
    try {
        return new Intl.RelativeTimeFormat(locale || undefined, { numeric: 'auto' })
    } catch {
        return null
    }
}

globalThis.blbFormatRelativeTimeElement = (element, now = Date.now()) => {
    if (!element) {
        return
    }

    const iso = element.getAttribute('datetime')

    if (!iso) {
        return
    }

    const then = new Date(iso).getTime()

    if (Number.isNaN(then)) {
        return
    }

    const formatter = blbResolveRelativeFormatter(element.dataset.locale)

    if (!formatter) {
        return
    }

    // Keep the server's text reachable: if anything below throws, or the page
    // is read without JS, the fallback is what the visitor already sees.
    const seconds = Math.round((then - now) / 1000)
    const magnitude = Math.abs(seconds)
    const [unit, size] = UNITS.find(([, s]) => magnitude >= s) ?? ['second', 1]

    element.textContent = formatter.format(Math.round(seconds / size), unit)
}

const blbRefreshRelativeTimes = () => {
    const now = Date.now()

    for (const element of document.querySelectorAll('time[data-blb-relative]')) {
        globalThis.blbFormatRelativeTimeElement(element, now)
    }
}

globalThis.blbRefreshRelativeTimes = blbRefreshRelativeTimes

// Re-derive on load, after every Livewire navigation or morph (a morph brings
// fresh server text that is correct at that instant, so this only matters for
// the elements it did not replace), and on a timer for pages left sitting open.
const start = () => {
    blbRefreshRelativeTimes()
    setInterval(blbRefreshRelativeTimes, TICK_MS)
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true })
} else {
    start()
}

document.addEventListener('livewire:navigated', blbRefreshRelativeTimes)
document.addEventListener('livewire:morphed', blbRefreshRelativeTimes)
