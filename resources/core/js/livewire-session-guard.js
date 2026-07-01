// Browsers never send the URL fragment (#hash) to the server, so when Laravel
// captures the "intended" URL for post-login redirect (from the Referer for
// non-GET / Livewire requests), the active tab's hash is lost. We stash the
// full href — fragment included — here before leaving for login, then re-apply
// the fragment on the page we land on after the redirect back.

const LOGIN_URL = globalThis.__BLB_LOGIN_URL__ || '/login'

const safeUrl = (input, base = globalThis.location.origin) => {
    try {
        return new URL(input, base)
    } catch {
        return null
    }
}

const LOGIN_PATHNAME = safeUrl(LOGIN_URL)?.pathname ?? '/login'

const isLoginPageUrl = (url) => safeUrl(url)?.pathname === LOGIN_PATHNAME

const INTENDED_URL_KEY = 'blb.intended_url'

const rememberIntendedUrl = () => {
    try {
        if (!isLoginPageUrl(globalThis.location.href)) {
            globalThis.sessionStorage.setItem(INTENDED_URL_KEY, globalThis.location.href)
        }
    } catch {
        // sessionStorage may be unavailable (private mode, disabled); carry on.
    }
}

const restoreIntendedUrlFragment = () => {
    // Stay out of the way on the login page itself: the entry must survive the
    // round-trip so it can be re-applied on the destination page after login.
    if (isLoginPageUrl(globalThis.location.href)) {
        return
    }

    let stored
    try {
        stored = globalThis.sessionStorage.getItem(INTENDED_URL_KEY)
        globalThis.sessionStorage.removeItem(INTENDED_URL_KEY)
    } catch {
        return
    }

    if (!stored) {
        return
    }

    const intended = safeUrl(stored)
    if (!intended) {
        return
    }

    // Only re-apply the fragment, and only when we landed on the very page the
    // user was on (server's url.intended points at the same path/query). A bare
    // replaceState — matching what x-ui.tabs does on select() — avoids a scroll
    // jump and an extra history entry; x-ui.tabs reads location.hash at init().
    if (!intended.hash
        || intended.pathname !== globalThis.location.pathname
        || intended.search !== globalThis.location.search
        || globalThis.location.hash === intended.hash) {
        return
    }

    globalThis.history.replaceState(null, '', intended.pathname + intended.search + intended.hash)
}

let redirectingToLogin = false

const redirectToLogin = (url = LOGIN_URL) => {
    if (redirectingToLogin) {
        return
    }

    redirectingToLogin = true
    rememberIntendedUrl()
    globalThis.location.assign(url)
}

const isAuthenticationStatus = (status) => [401, 419].includes(Number(status))

// --- bootstrap: runs at module load, before Alpine/Livewire init components ---

// Re-apply the active-tab fragment before x-ui.tabs reads location.hash.
restoreIntendedUrlFragment()

document.addEventListener('livewire:init', () => {
    if (!globalThis.Livewire?.hook) {
        return
    }

    globalThis.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (!isAuthenticationStatus(status)) {
                return
            }

            preventDefault?.()
            redirectToLogin()
        })
    })
})

const nativeFetch = globalThis.fetch.bind(globalThis)

globalThis.fetch = async (input, init) => {
    const response = await nativeFetch(input, init)

    if (response.redirected && isLoginPageUrl(response.url)) {
        redirectToLogin(response.url)

        return new Promise(() => {})
    }

    return response
}
