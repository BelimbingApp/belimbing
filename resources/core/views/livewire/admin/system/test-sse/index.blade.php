<div
    x-data="{
        streamUrl: @js($streamUrl),
        entries: [],
        entryLabel: @js(__('entries')),
        eventSource: null,
        elapsedSeconds: 0,
        elapsedTimer: null,
        lastFeedAt: @js(__('Waiting for first feed event...')),
        connectionState: @js(__('Idle')),
        completedState: @js(__('Completed')),
        startLabel: @js(__('Started long-lived coding-agent simulation')),
        waitLabel: @js(__('Connecting...')),
        reconnectLabel: @js(__('Reconnecting...')),
        openLabel: @js(__('Open')),
        stoppedLabel: @js(__('Stopped by operator.')),
        append(kind, message, payload = null) {
            this.entries.unshift({
                id: `${Date.now()}-${Math.random()}`,
                kind,
                message,
                payload,
                seenAt: new Date().toLocaleTimeString(),
            });
        },
        parse(data) {
            try {
                return JSON.parse(data);
            } catch {
                return { raw: data };
            }
        },
        formatElapsed(totalSeconds) {
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;

            return [hours, minutes, seconds]
                .map((value, index) => index === 0 ? String(value) : String(value).padStart(2, '0'))
                .join(':');
        },
        startElapsedTimer() {
            this.stopElapsedTimer();
            this.elapsedSeconds = 0;
            this.elapsedTimer = setInterval(() => {
                this.elapsedSeconds += 1;
            }, 1000);
        },
        stopElapsedTimer() {
            if (this.elapsedTimer) {
                clearInterval(this.elapsedTimer);
                this.elapsedTimer = null;
            }
        },
        start() {
            this.stop(false);
            this.connectionState = this.waitLabel;
            this.lastFeedAt = @js(__('Waiting for first feed event...'));
            this.startElapsedTimer();
            this.append('state', this.startLabel);

            const url = new URL(this.streamUrl, window.location.origin);
            url.searchParams.set('t', Date.now().toString());

            this.eventSource = new EventSource(url);

            this.eventSource.onopen = () => {
                this.connectionState = this.openLabel;
                this.append('open', @js(__('EventSource connected.')));
            };

            this.eventSource.onmessage = (event) => {
                const payload = this.parse(event.data);
                this.append('message', payload.message ?? @js(__('Received a default SSE message.')), payload);
            };

            this.eventSource.addEventListener('agent-feed', (event) => {
                const payload = this.parse(event.data);
                this.lastFeedAt = payload.sent_at ?? new Date().toLocaleTimeString();
                this.append(payload.event_type ?? 'agent-feed', payload.message ?? @js(__('Received a simulated agent event.')), payload);
            });

            this.eventSource.addEventListener('complete', (event) => {
                const payload = this.parse(event.data);
                this.connectionState = this.completedState;
                this.append('complete', payload.message ?? @js(__('The SSE stream completed.')), payload);
                this.stop(false);
            });

            this.eventSource.onerror = () => {
                if (this.connectionState === this.completedState || !this.eventSource) {
                    return;
                }

                this.connectionState = this.reconnectLabel;
                this.append('error', @js(__('The browser reported an SSE transport issue.')));
            };
        },
        stop(logClosure = true) {
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
            }

            this.stopElapsedTimer();

            if (logClosure) {
                this.connectionState = @js(__('Closed'));
                this.append('close', this.stoppedLabel);
            }
        },
    }"
    x-init="
        window.__testSseCleanup?.();
        const startStream = () => start();
        const stopStream = (logClosure = false) => stop(logClosure);
        startStream();

        const cleanup = () => stopStream(false);

        window.addEventListener('beforeunload', cleanup);
        document.addEventListener('alpine:navigate', cleanup);

        window.__testSseCleanup = () => {
            window.removeEventListener('beforeunload', cleanup);
            document.removeEventListener('alpine:navigate', cleanup);
            stopStream(false);
            window.__testSseCleanup = null;
        };
    "
>
    <x-slot name="title">{{ __('TestSSE') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('TestSSE')"
            :subtitle="__('Run a 10-minute EventSource coding-agent simulation and keep the SSE connection open while feed events arrive randomly over HTTP/2.')"
        />

        <x-ui.alert variant="default">
            {{ __('This page opens a real long-lived Server-Sent Events stream from the backend. The browser uses EventSource, the server keeps the connection open for 10 minutes, and simulated coding-agent feed events arrive at random intervals between :min and :max seconds.', ['min' => $minFeedIntervalSeconds, 'max' => $maxFeedIntervalSeconds]) }}
        </x-ui.alert>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            <x-ui.card>
                <div class="space-y-4">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Connection state') }}</p>
                        <p class="mt-1 text-sm text-ink" x-text="connectionState"></p>
                    </div>

                    <div class="space-y-2 text-sm">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Elapsed') }}</p>
                            <p class="mt-1 font-medium tabular-nums text-ink" x-text="formatElapsed(elapsedSeconds)"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Last feed event') }}</p>
                            <p class="mt-1 text-ink" x-text="lastFeedAt"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Endpoint') }}</p>
                            <code class="mt-1 block break-all rounded-2xl bg-surface-subtle px-3 py-2 text-xs text-ink">{{ $streamUrl }}</code>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Transport') }}</p>
                            <p class="mt-1 text-ink">{{ __('SSE via EventSource over HTTP/2') }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Simulation profile') }}</p>
                            <p class="mt-1 text-ink">
                                {{ __('10 minutes total, feed interval :min-:max seconds.', ['min' => $minFeedIntervalSeconds, 'max' => $maxFeedIntervalSeconds]) }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="button" variant="primary" @click="start()">
                            {{ __('Reconnect stream') }}
                        </x-ui.button>
                        <x-ui.button type="button" variant="ghost" @click="stop()">
                            {{ __('Stop stream') }}
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
                        <p class="text-xs text-muted">{{ __('Newest entry first. The browser should receive coding-agent style feed events progressively while the stream remains open.') }}</p>
                    </div>
                    <p class="text-xs text-muted" x-text="entries.length + ' ' + entryLabel"></p>
                </div>

                <div class="mt-4 space-y-3">
                    <template x-if="entries.length === 0">
                        <div class="rounded-2xl border border-dashed border-border-default bg-surface-subtle px-4 py-6 text-sm text-muted">
                            {{ __('Waiting for the browser to receive SSE data...') }}
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
