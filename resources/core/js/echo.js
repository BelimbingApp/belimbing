const driver = globalThis.__BLB_BROADCAST_DRIVER__
const useReverb = driver === 'reverb' && import.meta.env.VITE_REVERB_APP_KEY
const usePusher = driver === 'pusher' && import.meta.env.VITE_PUSHER_APP_KEY
const notifyEchoReady = () => globalThis.dispatchEvent(new CustomEvent('blb-echo-ready'))
const notifyEchoFailure = (error) => {
    console.error('Failed to initialize Echo.', error)
    globalThis.dispatchEvent(new CustomEvent('blb-echo-failed', {
        detail: { message: error instanceof Error ? error.message : String(error) },
    }))
}

if (useReverb) {
    try {
        const Echo = (await import('laravel-echo')).default
        const Pusher = (await import('pusher-js')).default

        globalThis.Pusher = Pusher
        globalThis.Echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
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
