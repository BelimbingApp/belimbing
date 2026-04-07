<div
    x-data="{
        channelName: @js($channelName),
        eventName: @js($eventName),
        dispatchUrl: @js($dispatchUrl),
        entries: [],
        entryLabel: @js(__('entries')),
        lastFeedAt: @js(__('Waiting for first Reverb event...')),
        connectionState: @js(__('Waiting for Echo...')),
        dispatching: false,
        echoChannel: null,
        connectionCleanup: null,
        subscribed: false,
        append(kind, message, payload = null) {
            this.entries.unshift({
                id: `${Date.now()}-${Math.random()}`,
                kind,
                message,
                payload,
                seenAt: new Date().toLocaleTimeString(),
            });
        },
        bindConnectionState(pusher) {
            if (this.connectionCleanup) {
                return;
            }

            const handleStateChange = (states) => {
                const state = states?.current ?? pusher.connection.state ?? 'unknown';

                this.connectionState = state === 'connected'
                    ? @js(__('Connected to Reverb. Subscribing to the channel...'))
                    : @js(__('Reverb socket state: :state', ['state' => ':state'])).replace(':state', state);
            };

            const handleError = (error) => {
                const message = error?.error?.message ?? error?.message ?? null;

                this.connectionState = message
                    ? @js(__('Reverb connection failed:')).concat(' ', message)
                    : @js(__('Reverb connection failed.'));

                this.append('error', this.connectionState, error ?? null);
            };

            pusher.connection.bind('state_change', handleStateChange);
            pusher.connection.bind('error', handleError);

            handleStateChange({ current: pusher.connection.state });

            this.connectionCleanup = () => {
                pusher.connection.unbind('state_change', handleStateChange);
                pusher.connection.unbind('error', handleError);
                this.connectionCleanup = null;
            };
        },
        subscribe() {
            const echo = window.Echo;
            const pusher = echo?.connector?.pusher;

            if (!echo || !pusher || this.echoChannel) {
                return;
            }

            this.bindConnectionState(pusher);
            this.connectionState = @js(__('Subscribing to the Reverb channel...'));

            this.echoChannel = echo.channel(this.channelName)
                .subscribed(() => {
                    this.subscribed = true;
                    this.connectionState = @js(__('Listening on the Reverb channel.'));
                    this.append('state', @js(__('Reverb subscription is active.')));
                })
                .error((error) => {
                    this.subscribed = false;
                    this.echoChannel = null;
                    this.connectionState = error?.message
                        ? @js(__('Subscription failed:')).concat(' ', error.message)
                        : @js(__('Subscription failed.'));
                    this.append('error', this.connectionState, error ?? null);
                })
                .listen(`.${this.eventName}`, (payload) => {
                    this.lastFeedAt = payload.sent_at ?? new Date().toLocaleTimeString();
                    this.append(payload.event_type ?? 'message', payload.message ?? @js(__('Received a Reverb event.')), payload);
                });
        },
        async dispatchBurst() {
            if (this.dispatching) {
                return;
            }

            this.dispatching = true;

            try {
                const response = await fetch(this.dispatchUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    body: JSON.stringify({}),
                });

                if (!response.ok) {
                    throw new Error(@js(__('The dispatch request failed.')));
                }
            } catch (error) {
                this.append(
                    'error',
                    error instanceof Error ? error.message : @js(__('The dispatch request failed.')),
                    error instanceof Error ? { message: error.message } : null,
                );
            } finally {
                this.dispatching = false;
            }
        },
        unsubscribe() {
            const echo = window.Echo;

            this.connectionCleanup?.();
            echo?.leaveChannel(this.channelName);
            this.echoChannel = null;
            this.subscribed = false;
            this.connectionState = @js(__('Unsubscribed.'));
            this.append('state', this.connectionState);
        },
    }"
    x-init="
        window.__testReverbCleanup?.();
        const subscribeChannel = () => subscribe();
        const unsubscribeChannel = () => unsubscribe();

        const onReady = () => subscribeChannel();
        const onFailure = (event) => {
            connectionState = event?.detail?.message
                ? @js(__('Echo failed:')).concat(' ', event.detail.message)
                : @js(__('Echo failed to initialize.'));
            append('error', connectionState);
        };
        const cleanup = () => {
            window.removeEventListener('blb-echo-ready', onReady);
            window.removeEventListener('blb-echo-failed', onFailure);
            document.removeEventListener('alpine:navigate', cleanup);
            window.removeEventListener('beforeunload', cleanup);
            unsubscribeChannel();
            window.__testReverbCleanup = null;
        };

        if (window.Echo) {
            subscribeChannel();
        } else {
            window.addEventListener('blb-echo-ready', onReady, { once: true });
            window.addEventListener('blb-echo-failed', onFailure, { once: true });
        }

        document.addEventListener('alpine:navigate', cleanup);
        window.addEventListener('beforeunload', cleanup);
        window.__testReverbCleanup = cleanup;
    "
>
    <x-slot name="title">{{ __('TestReverb') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('TestReverb')"
            :subtitle="__('Subscribe the browser to a user-scoped Reverb channel, then dispatch a multi-turn coding-agent simulation into the live log over WebSocket.')"
        />

        @if ($broadcastDriver !== 'reverb')
            <x-ui.alert variant="warning">
                {{ __('The current broadcast driver is :driver, so this page is not running against Reverb yet.', ['driver' => $broadcastDriver]) }}
            </x-ui.alert>
        @else
            <x-ui.alert variant="default">
                {{ __('This page keeps the browser subscribed to a real Reverb channel. Use the dispatch button to emit a finite coding-agent transcript with multiple turns, tool events, and assistant deltas, then confirm the browser receives each WebSocket event live.') }}
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            <x-ui.card>
                <div class="space-y-4">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Connection state') }}</p>
                        <p class="mt-1 text-sm text-ink" x-text="connectionState"></p>
                    </div>

                    <div class="space-y-2 text-sm">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Last feed event') }}</p>
                            <p class="mt-1 text-ink" x-text="lastFeedAt"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Broadcast driver') }}</p>
                            <p class="mt-1 text-ink">{{ $broadcastDriver }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Channel') }}</p>
                            <code class="mt-1 block break-all rounded-2xl bg-surface-subtle px-3 py-2 text-xs text-ink">{{ $channelName }}</code>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Transport') }}</p>
                            <p class="mt-1 text-ink">{{ __('Reverb via WebSocket') }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Simulation profile') }}</p>
                            <p class="mt-1 text-ink">
                                {{ __(':turns turns, :events total events, roughly :ms ms between dispatches.', ['turns' => $turnCount, 'events' => $eventCount, 'ms' => $burstIntervalMs]) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Event name') }}</p>
                            <code class="mt-1 block break-all rounded-2xl bg-surface-subtle px-3 py-2 text-xs text-ink">{{ $eventName }}</code>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="button" variant="primary" @click="dispatchBurst()" x-bind:disabled="dispatching">
                            {{ __('Dispatch :turns turns (:events events)', ['turns' => $turnCount, 'events' => $eventCount]) }}
                        </x-ui.button>
                        <x-ui.button type="button" variant="ghost" @click="entries = []">
                            {{ __('Clear log') }}
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-medium text-ink">{{ __('Live event log') }}</h3>
                        <p class="text-xs text-muted">{{ __('Newest entry first. The browser should receive coding-agent turn events progressively from Echo while the HTTP dispatch request simply triggers the backend burst.') }}</p>
                    </div>
                    <p class="text-xs text-muted" x-text="entries.length + ' ' + entryLabel"></p>
                </div>

                <div class="mt-4 space-y-3">
                    <template x-if="entries.length === 0">
                        <div class="rounded-2xl border border-dashed border-border-default bg-surface-subtle px-4 py-6 text-sm text-muted">
                            {{ __('Waiting for backend Reverb broadcasts...') }}
                        </div>
                    </template>

                    <template x-for="entry in entries" :key="entry.id">
                        <article class="rounded-2xl border border-border-default bg-surface-subtle px-4 py-3">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted" x-text="entry.kind"></p>
                                    <p class="mt-1 text-sm text-ink" x-text="entry.message"></p>
                                </div>
                                <time class="shrink-0 text-xs tabular-nums text-muted" x-text="entry.seenAt"></time>
                            </div>

                            <template x-if="entry.payload">
                                <pre class="mt-3 overflow-x-auto rounded-2xl bg-surface-card px-3 py-2 text-xs text-ink" x-text="JSON.stringify(entry.payload, null, 2)"></pre>
                            </template>
                        </article>
                    </template>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
