{{--
    Global notification outlet. Listens for the `notify` browser event (emitted by
    the `InteractsWithNotifications` Livewire trait) and renders stacked
    notifications at the top-right via `x-ui.flash-stack`. Mounted once in the app
    layout.

    Persistence is severity-tiered: `error`/`warning` stay until the user closes
    them (must not be missed); `success`/`info` auto-dismiss after a short dwell.
    A close button is always present. An explicit `duration` (ms) in the event
    forces a timer on any variant.

    This owns the *same-page* feedback lane; inline `x-ui.alert` /
    `x-ui.session-flash` own persistent page context and post-redirect banners.
    Variant styling (surface/border/text + glyph path) comes from the shared
    `StatusVariant` source, also used by `x-ui.alert` and `x-ui.flash`.
--}}
@php
    $notificationVariants = \App\Base\Foundation\Enums\StatusVariant::jsMap();
@endphp

<div
    x-data="{
        notifications: [],
        variants: @js($notificationVariants),
        sticky: ['error', 'warning'],
        _seq: 0,
        push(detail) {
            const message = detail?.message ?? null;
            if (! message) return;
            const variant = (detail?.variant && this.variants[detail.variant]) ? detail.variant : 'success';
            const id = ++this._seq;
            this.notifications.push({ id, message, variant });

            const override = Number(detail?.duration);
            let ttl = null;
            if (override > 0) {
                ttl = override;
            } else if (! this.sticky.includes(variant)) {
                ttl = 4700;
            }
            if (ttl) setTimeout(() => this.dismiss(id), ttl);
        },
        dismiss(id) {
            this.notifications = this.notifications.filter((n) => n.id !== id);
        },
    }"
    @notify.window="push($event.detail)"
>
    <x-ui.flash-stack width="wide">
        <template x-for="item in notifications" :key="item.id">
            <div
                class="w-full"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-1 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
            >
                <div
                    class="pointer-events-auto flex w-full items-start gap-3 rounded-2xl border px-4 py-3 shadow-lg shadow-black/5"
                    :class="`${variants[item.variant].bg} ${variants[item.variant].border} ${variants[item.variant].text}`"
                    :role="sticky.includes(item.variant) ? 'alert' : 'status'"
                    :aria-live="sticky.includes(item.variant) ? 'assertive' : 'polite'"
                >
                    <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="variants[item.variant].path" />
                    </svg>
                    <p class="min-w-0 flex-1 text-xs leading-5" x-text="item.message"></p>
                    <button
                        type="button"
                        class="shrink-0 opacity-60 transition-opacity hover:opacity-100"
                        @click="dismiss(item.id)"
                        :aria-label="@js(__('Dismiss'))"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </template>
    </x-ui.flash-stack>
</div>
