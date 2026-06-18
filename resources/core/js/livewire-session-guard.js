const loginUrl = () => globalThis.__BLB_LOGIN_URL__ || '/login'

let redirectingToLogin = false

const redirectToLogin = () => {
    if (redirectingToLogin) {
        return
    }

    redirectingToLogin = true
    globalThis.location.assign(loginUrl())
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
