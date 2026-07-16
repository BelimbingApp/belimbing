// Sidebar menu Alpine component (window-function pattern, like blbAppShell).
//
// Extracted from an inline x-data on x-menu.sidebar: the methods below were
// ~100 KB of attribute text duplicated on BOTH sidebar copies (desktop column
// and mobile drawer) on every full page load. Only per-page data (pins, menu
// items, icon refs) stays inline via @js.
// Shared data (pins, menu items, icon refs, pin API urls) is emitted once by
// the app layout as #blb-menu-data; both sidebar instances read the same blob.
const readMenuData = () => {
    try {
        return JSON.parse(document.getElementById('blb-menu-data')?.textContent ?? '{}')
    } catch {
        return {}
    }
}

globalThis.blbSidebarMenu = ({ honorRail = true } = {}) => {
    const {
        pins = [],
        defaultRailPinIcon = '',
        pinRailIconSvgs = {},
        menuItemsFlat = {},
        toggleUrl = '',
        reorderUrl = '',
    } = readMenuData()

    return {
    pins,
    _dragIdx: null,
    _dropIdx: null,

    // Boot beacon: the app layout renders a fail-visible "could not finish
    // loading" notice (#blb-boot-beacon) that reveals itself after a few
    // seconds. Reaching init() proves the failure class it guards against
    // (stale/missing bundle → Alpine never boots the sidebar → every menu
    // item stays x-cloak-hidden) did not happen, so remove it before it
    // shows. The shell-store check keeps that proof honest: without the
    // store, the `rail` getter would throw right after init and the menu
    // would still come up blank.
    init() {
        if (this.$store.shell !== undefined) {
            document.getElementById('blb-boot-beacon')?.remove()
        }
    },

    // Rail (icon-only) is a desktop-column affordance driven by the global
    // shell store. The mobile drawer has a fixed width with room for labels,
    // so it opts out (honorRail=false) and always renders expanded. Reading
    // a scoped `rail` here (instead of $store.shell.rail directly) lets the
    // whole menu tree resolve it up the Alpine scope chain.
    honorRail,
    get rail() {
        return this.honorRail ? this.$store.shell.rail : false
    },

    _normalizeUrl(url) {
        try {
            const u = new URL(url, window.location.origin)
            let path = u.pathname
            while (path.length > 1 && path.endsWith('/')) {
                path = path.slice(0, -1)
            }
            return path || '/'
        } catch {
            return url
        }
    },

    isPinnedByUrl(url) {
        const needle = this._normalizeUrl(url)
        return this.pins.some(p => this._normalizeUrl(p.url) === needle)
    },

    _acquireLock() {
        if (window.__pinBusy) return false
        window.__pinBusy = true
        return true
    },
    _releaseLock() {
        window.__pinBusy = false
    },
    _syncPins(pins) {
        this.pins = pins
        window.dispatchEvent(new CustomEvent('pins-synced', { detail: { pins } }))
    },

    _apiHeaders() {
        return {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            'Accept': 'application/json',
        }
    },

    _toggleByUrl(label, url, icon) {
        if (!this._acquireLock()) return

        const wasPinned = this.isPinnedByUrl(url)
        const prevPins = [...this.pins]

        if (wasPinned) {
            const needle = this._normalizeUrl(url)
            this.pins = this.pins.filter(p => this._normalizeUrl(p.url) !== needle)
        } else {
            this.pins.push({ id: null, label, url, icon: icon ?? null })
        }

        fetch(toggleUrl, {
            method: 'POST',
            headers: this._apiHeaders(),
            body: JSON.stringify({ label, url, icon: icon ?? null }),
        })
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`Pin toggle failed with status ${r.status}`)))
            .then(data => { this._syncPins(data.pins) })
            .catch(() => { this._syncPins(prevPins) })
            .finally(() => { this._releaseLock() })
    },

    togglePin(id) {
        const item = this.menuItemsFlat[id]
        if (!item) return
        this._toggleByUrl(item.pinLabel, item.href, item.icon)
    },

    unpinFromSidebar(pin) {
        this._toggleByUrl(pin.label, pin.url, pin.icon)
    },

    togglePagePin(detail) {
        const { label, url, icon } = detail
        this._toggleByUrl(label, url, icon)
    },

    reorderPins(orderedPins) {
        this.pins = orderedPins

        fetch(reorderUrl, {
            method: 'POST',
            headers: this._apiHeaders(),
            body: JSON.stringify({
                pins: orderedPins.map(pin => ({ id: pin.id })),
            }),
        })
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`Pin reorder failed with status ${r.status}`)))
            .then(data => { this.pins = data.pins })
            .catch(() => {
                // Silently keep optimistic order on failure
            })
    },

    // Drag-reorder handlers
    pinDragStart(idx, event) {
        this._dragIdx = idx
        event.dataTransfer.effectAllowed = 'move'
        event.dataTransfer.setData('text/plain', idx)
    },
    pinDragOver(idx, event) {
        event.preventDefault()
        event.dataTransfer.dropEffect = 'move'
        this._dropIdx = idx
    },
    pinDrop(idx) {
        if (this._dragIdx === null || this._dragIdx === idx) {
            this._dragIdx = null
            this._dropIdx = null
            return
        }
        const reorderedPins = [...this.pins]
        const [moved] = reorderedPins.splice(this._dragIdx, 1)
        reorderedPins.splice(idx, 0, moved)
        this._dragIdx = null
        this._dropIdx = null
        this.reorderPins(reorderedPins)
    },
    pinDragEnd() {
        this._dragIdx = null
        this._dropIdx = null
    },

    defaultRailPinIcon,
    pinRailIconSvgs,
    pinRailIconName(pin) {
        if (pin?.icon) {
            return pin.icon
        }
        const needle = this._normalizeUrl(pin.url)
        for (const id in this.menuItemsFlat) {
            const item = this.menuItemsFlat[id]
            if (item?.href && this._normalizeUrl(item.href) === needle) {
                return item.icon ?? this.defaultRailPinIcon
            }
        }
        return this.defaultRailPinIcon
    },
    pinRailIconHtml(pin) {
        const name = this.pinRailIconName(pin)
        return this.pinRailIconSvgs[name] ?? this.pinRailIconSvgs[this.defaultRailPinIcon]
    },

    menuItemsFlat,
    }
}
