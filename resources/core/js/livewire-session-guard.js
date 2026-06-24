const loginPathname = () => {
    try {
        return new URL(globalThis.__BLB_LOGIN_URL__ || '/login', globalThis.location.origin).pathname
    } catch {
        return '/login'
    }
}

const isLoginPageUrl = (url) => {
    try {
        return new URL(url, globalThis.location.origin).pathname === loginPathname()
    } catch {
        return false
    }
}

let redirectingToLogin = false

const redirectToLogin = (url = globalThis.__BLB_LOGIN_URL__ || '/login') => {
    if (redirectingToLogin) {
        return
    }

    redirectingToLogin = true
    globalThis.location.assign(url)
}

const isAuthenticationStatus = (status) => [401, 419].includes(Number(status))

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
