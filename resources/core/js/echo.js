const driver = globalThis.__BLB_BROADCAST_DRIVER__
const useReverb = driver === 'reverb' && import.meta.env.VITE_REVERB_APP_KEY
const usePusher = driver === 'pusher' && import.meta.env.VITE_PUSHER_APP_KEY
const notifyEchoReady = () => window.dispatchEvent(new CustomEvent('blb-echo-ready'))
const notifyEchoFailure = (error) => {
    console.error('Failed to initialize Echo.', error)
    window.dispatchEvent(new CustomEvent('blb-echo-failed', {
        detail: { message: error instanceof Error ? error.message : String(error) },
    }))
}

if (useReverb) {
    try {
        const Echo = (await import('laravel-echo')).default
        const Pusher = (await import('pusher-js')).default
        // Use app host (Caddy) when page is HTTPS so wss works; Caddy proxies to Reverb
        const isSecure = typeof location !== 'undefined' && location.protocol === 'https:'
        const wsHost = isSecure ? location.hostname : (import.meta.env.VITE_REVERB_HOST || 'localhost')
        const wssPort = isSecure ? (Number.parseInt(location.port, 10) || 443) : (import.meta.env.VITE_REVERB_PORT ?? 443)
        const useTLS = isSecure

        globalThis.Pusher = Pusher
        globalThis.Echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort,
            forceTLS: useTLS,
            enabledTransports: useTLS ? ['wss'] : ['ws', 'wss'],
        })

        notifyEchoReady()
    } catch (error) {
        notifyEchoFailure(error)
    }
} else if (usePusher) {
    try {
        const Echo = (await import('laravel-echo')).default
        const Pusher = (await import('pusher-js')).default

        globalThis.Pusher = Pusher
        globalThis.Echo = new Echo({
            broadcaster: 'pusher',
            key: import.meta.env.VITE_PUSHER_APP_KEY,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
        })

        notifyEchoReady()
    } catch (error) {
        notifyEchoFailure(error)
    }
}
