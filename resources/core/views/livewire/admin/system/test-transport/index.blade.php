<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\System\Livewire\TestTransport\Index $this */
?>
<div
    x-data="{
        streamUrl: @js($streamUrl),
        selectedTurnId: $wire.entangle('selectedTurnId'),
        speed: $wire.entangle('speed'),
        streaming: false,
        streamEntries: [],
        diagnostics: [],
        turnPhase: null,
        turnLabel: null,
        elapsedSeconds: 0,
        _elapsedTimer: null,
        _reader: null,
        _abortController: null,
        _startedAt: null,
        _eventCount: 0,
        _totalLatency: 0,
        _gapCount: 0,
        _lastSeq: 0,
        _toolMap: {},
        _completedToolCount: 0,
        toolsCollapsed: false,
        _deltaBuffer: '',
        _deltaFlushTimer: null,

        formatElapsed(totalSeconds) {
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;

            return [hours, minutes, seconds]
                .map((value, index) => index === 0 ? String(value) : String(value).padStart(2, '0'))
                .join(':');
        },

        async start() {
            if (!this.selectedTurnId || this.streaming) return;
            this.stop(false);

            this.streaming = true;
            this.streamEntries = [];
            this.diagnostics = [];
            this._eventCount = 0;
            this._totalLatency = 0;
            this._gapCount = 0;
            this._lastSeq = 0;
            this._toolMap = {};
            this._completedToolCount = 0;
            this.toolsCollapsed = false;
            this._deltaBuffer = '';
            this._startedAt = Date.now();
            this.elapsedSeconds = 0;
            this._elapsedTimer = setInterval(() => {
                this.elapsedSeconds = Math.floor((Date.now() - this._startedAt) / 1000);
            }, 1000);

            this._abortController = new AbortController();

            const url = new URL(this.streamUrl, window.location.origin);
            url.searchParams.set('turn_id', this.selectedTurnId);
            url.searchParams.set('speed', String(this.speed));

            try {
                const response = await fetch(url, {
                    signal: this._abortController.signal,
                    credentials: 'same-origin',
                });

                if (!response.ok || !response.body) {
                    this.diagnostics.unshift({
                        time: new Date().toLocaleTimeString(),
                        message: @js(__('Stream failed: HTTP')) + ' ' + response.status,
                    });
                    this.stop(false);

                    return;
                }

                this._reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await this._reader.read();

                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (const line of lines) {
                        if (!line.trim()) continue;

                        try {
                            const data = JSON.parse(line);

                            if (data._stream_complete) {
                                this.diagnostics.unshift({
                                    time: new Date().toLocaleTimeString(),
                                    message: @js(__('Stream complete.')),
                                });
                                this.flushDeltaBuffer();
                                this.stop(false);

                                return;
                            }

                            this.handleEvent(data);
                        } catch {
                            // Skip malformed lines
                        }
                    }
                }

                this.flushDeltaBuffer();
                this.stop(false);
            } catch (e) {
                if (e.name !== 'AbortError') {
                    this.diagnostics.unshift({
                        time: new Date().toLocaleTimeString(),
                        message: @js(__('Stream error:')) + ' ' + (e.message || @js(__('Unknown'))),
                    });
                }

                this.stop(false);
            }
        },

        stop(logClosure = true) {
            if (this._abortController) {
                this._abortController.abort();
                this._abortController = null;
            }

            this._reader = null;
            this.streaming = false;

            if (this._elapsedTimer) {
                clearInterval(this._elapsedTimer);
                this._elapsedTimer = null;
            }

            if (this._deltaFlushTimer) {
                clearTimeout(this._deltaFlushTimer);
                this._deltaFlushTimer = null;
            }

            if (logClosure) {
                this.diagnostics.unshift({
                    time: new Date().toLocaleTimeString(),
                    message: @js(__('Stopped by operator.')),
                });
            }
        },

        handleEvent(data) {
            const receivedAt = Date.now();
            this._eventCount++;

            const seq = Number.parseInt(data.seq ?? 0, 10) || 0;

            if (seq && seq > this._lastSeq + 1) {
                this._gapCount += (seq - this._lastSeq - 1);
            }

            if (seq) this._lastSeq = seq;

            this.diagnostics.unshift({
                time: new Date().toLocaleTimeString(),
                seq,
                eventType: data.event_type,
                replayDelayMs: data._replay_delay_ms ?? 0,
                pacedDelayMs: data._paced_delay_ms ?? 0,
            });

            if (this.diagnostics.length > 200) {
                this.diagnostics.length = 200;
            }

            this.processEvent(data.event_type, data);
        },

        processEvent(eventType, data) {
            switch (eventType) {
                case 'turn.started':
                    this.turnPhase = 'booting';
                    this.turnLabel = @js(__('Starting…'));
                    break;

                case 'turn.phase_changed': {
                    const phase = data.payload?.phase || data.phase;
                    const label = data.payload?.label || data.label || phase;
                    this.turnPhase = phase;
                    this.turnLabel = label;

                    if (phase === 'thinking' && label && label !== @js(__('Thinking…'))) {
                        const thinking = this.streamEntries.find((e) => e.type === 'thinking');

                        if (thinking) {
                            thinking.description = label.replace(/^Thinking\s*—\s*/, '');
                        }
                    }

                    break;
                }

                case 'run.started':
                    break;

                case 'assistant.thinking_started':
                    this.onThinkingStarted(data);
                    break;

                case 'tool.started':
                    this.onToolStarted(data);
                    break;

                case 'tool.finished':
                    this.onToolFinished(data);
                    break;

                case 'tool.stdout_delta':
                    this.onToolStdoutDelta(data);
                    break;

                case 'tool.denied':
                    this.onToolDenied(data);
                    break;

                case 'assistant.output_delta':
                    this.onOutputDelta(data);
                    break;

                case 'assistant.output_block_committed':
                    this.flushDeltaBuffer();
                    break;

                case 'turn.completed':
                case 'turn.ready_for_input':
                    this.removeThinkingEntries();
                    this.turnPhase = 'completed';
                    this.turnLabel = @js(__('Completed'));
                    break;

                case 'turn.failed': {
                    const payload = data.payload || data;
                    this.streamEntries.push({
                        type: 'error',
                        message: payload.message || @js(__('Turn failed')),
                    });
                    this.turnPhase = 'failed';
                    this.turnLabel = @js(__('Failed'));
                    break;
                }

                case 'turn.cancelled':
                    this.turnPhase = 'cancelled';
                    this.turnLabel = @js(__('Cancelled'));
                    break;
            }
        },

        onThinkingStarted(data) {
            const payload = data?.payload || data || {};
            const description = payload.description || null;
            const existing = this.streamEntries.find((entry) => entry.type === 'thinking');

            if (!existing) {
                this.streamEntries.push({ type: 'thinking', active: true, description });
            } else {
                existing.active = true;

                if (description) existing.description = description;
            }
        },

        onToolStarted(data) {
            this.deactivateThinking();

            if (this._completedToolCount > 0 && !this.toolsCollapsed) {
                this.toolsCollapsed = true;
            }

            const payload = data.payload || data;
            const idx = this.streamEntries.length;
            const toolKey = payload.tool_call_index ?? idx;
            this._toolMap[toolKey] = idx;

            this.streamEntries.push({
                type: 'tool_call',
                tool: payload.tool || '',
                argsSummary: payload.args_summary || '',
                status: 'running',
                stdoutBuffer: '',
                collapsed: false,
            });
        },

        onToolFinished(data) {
            const payload = data.payload || data;
            const toolKey = payload.tool_call_index ?? -1;
            const callIdx = this._toolMap[toolKey];

            if (callIdx === undefined || !this.streamEntries[callIdx]) return;

            const entry = this.streamEntries[callIdx];
            entry.status = payload.status || 'success';
            entry.durationMs = payload.duration_ms;
            entry.resultPreview = payload.result_preview || '';
            entry.resultLength = payload.result_length || 0;
            entry.errorPayload = payload.error_payload || null;
            this._completedToolCount++;
        },

        onToolStdoutDelta(data) {
            const payload = data.payload || data;
            const toolName = payload.tool || '';
            const delta = payload.delta || '';

            if (!delta) return;

            for (let i = this.streamEntries.length - 1; i >= 0; i--) {
                const entry = this.streamEntries[i];

                if (entry.type === 'tool_call' && entry.tool === toolName && entry.status === 'running') {
                    if ((entry.stdoutBuffer || '').length < 10240) {
                        entry.stdoutBuffer = (entry.stdoutBuffer || '') + delta;
                    }

                    break;
                }
            }
        },

        onToolDenied(data) {
            const payload = data.payload || data;
            this.streamEntries.push({
                type: 'hook_action',
                action: 'tool_denied',
                tool: payload.tool || '',
                reason: payload.reason || '',
            });
        },

        onOutputDelta(data) {
            this.deactivateThinking();

            const payload = data.payload || data;
            const text = payload.delta || payload.text || '';

            if (!text) return;

            this._deltaBuffer += text;

            if (/\n/.test(text)) {
                this.flushDeltaBuffer();

                return;
            }

            if (this._deltaFlushTimer) clearTimeout(this._deltaFlushTimer);
            this._deltaFlushTimer = setTimeout(() => this.flushDeltaBuffer(), 80);
        },

        flushDeltaBuffer() {
            if (this._deltaFlushTimer) {
                clearTimeout(this._deltaFlushTimer);
                this._deltaFlushTimer = null;
            }

            if (!this._deltaBuffer) return;

            const last = this.streamEntries[this.streamEntries.length - 1];

            if (last && last.type === 'assistant_streaming') {
                last.content += this._deltaBuffer;
            } else {
                this.streamEntries.push({
                    type: 'assistant_streaming',
                    content: this._deltaBuffer,
                });
            }

            this._deltaBuffer = '';
        },

        deactivateThinking() {
            const thinking = this.streamEntries.find((entry) => entry.type === 'thinking');

            if (thinking) thinking.active = false;
        },

        removeThinkingEntries() {
            this.streamEntries = this.streamEntries.filter((entry) => entry.type !== 'thinking');
        },
    }"
    x-init="
        window.__testTransportCleanup?.();
        const cleanup = () => {
            stop(false);
            window.__testTransportCleanup = null;
        };
        window.addEventListener('beforeunload', cleanup);
        document.addEventListener('alpine:navigate', cleanup);
        window.__testTransportCleanup = () => {
            window.removeEventListener('beforeunload', cleanup);
            document.removeEventListener('alpine:navigate', cleanup);
            cleanup();
        };
    "
>
    <x-slot name="title">{{ __('TestTransport') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('TestTransport')"
            :subtitle="__('Replay real turn events through a persistent-fetch transport to evaluate latency, ordering, and UX fidelity.')"
        />

        <x-ui.alert variant="default">
            {{ __('This page reads real turn events from the database and streams them through a persistent fetch connection with paced delivery. Select a turn, choose a speed multiplier, and observe how the events render in real time.') }}
        </x-ui.alert>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            {{-- Left panel: controls --}}
            <div class="space-y-4">
                <x-ui.card>
                    <div class="space-y-4">
                        {{-- Turn picker --}}
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Select turn') }}</p>
                            <select
                                id="test-transport-turn"
                                x-model="selectedTurnId"
                                class="mt-1 w-full rounded-lg border border-border-input bg-surface-card px-input-x py-input-y text-sm text-ink focus:ring-2 focus:ring-accent focus:ring-offset-2"
                            >
                                <option value="">{{ __('Choose a turn…') }}</option>
                                @foreach ($turns as $turn)
                                    <option value="{{ $turn->id }}">
                                        {{ $turn->status->value }} — {{ $turn->events_count }} {{ __('events') }} — {{ $turn->created_at->format('M d H:i') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Speed multiplier --}}
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Speed') }}</p>
                            <div class="mt-1 flex gap-1.5">
                                @foreach ([1, 2, 5, 10] as $s)
                                    <button
                                        type="button"
                                        x-on:click="speed = {{ $s }}"
                                        class="rounded-lg px-3 py-1.5 text-xs font-medium transition-colors"
                                        :class="speed === {{ $s }}
                                            ? 'bg-accent text-accent-on'
                                            : 'bg-surface-subtle text-muted hover:text-ink'"
                                    >{{ $s }}×</button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Transport info --}}
                        <div class="space-y-2 text-sm">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Transport') }}</p>
                                <p class="mt-1 text-ink">{{ __('Persistent fetch (ReadableStream)') }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Elapsed') }}</p>
                                <p class="mt-1 font-medium tabular-nums text-ink" x-text="formatElapsed(elapsedSeconds)"></p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Phase') }}</p>
                                <p class="mt-1 text-ink" x-text="turnLabel || @js(__('Idle'))"></p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Events received') }}</p>
                                <p class="mt-1 tabular-nums text-ink" x-text="_eventCount"></p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Gaps detected') }}</p>
                                <p class="mt-1 tabular-nums text-ink" x-text="_gapCount"></p>
                            </div>
                        </div>

                        {{-- Actions --}}
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="button" variant="primary" @click="start()" x-bind:disabled="streaming || !selectedTurnId">
                                {{ __('Start replay') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="ghost" @click="stop()">
                                {{ __('Stop') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="ghost" @click="streamEntries = []; diagnostics = []">
                                {{ __('Clear') }}
                            </x-ui.button>
                        </div>
                    </div>
                </x-ui.card>

                {{-- Diagnostics log --}}
                <x-ui.card>
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-medium text-ink">{{ __('Event log') }}</h3>
                        <p class="text-xs text-muted" x-text="diagnostics.length + ' ' + @js(__('entries'))"></p>
                    </div>

                    <div class="mt-3 max-h-80 overflow-y-auto space-y-1">
                        <template x-if="diagnostics.length === 0">
                            <p class="text-xs text-muted py-3">{{ __('No events yet.') }}</p>
                        </template>

                        <template x-for="(d, i) in diagnostics" :key="i">
                            <div class="flex items-baseline gap-2 text-[11px] font-mono text-muted">
                                <span class="shrink-0 tabular-nums" x-text="d.time"></span>
                                <template x-if="d.seq">
                                    <span class="shrink-0 tabular-nums" x-text="'#' + d.seq"></span>
                                </template>
                                <span class="text-ink truncate" x-text="d.eventType || d.message"></span>
                                <template x-if="d.replayDelayMs !== undefined && d.replayDelayMs > 0">
                                    <span class="shrink-0 tabular-nums text-muted" x-text="d.replayDelayMs + 'ms'"></span>
                                </template>
                            </div>
                        </template>
                    </div>
                </x-ui.card>
            </div>

            {{-- Right panel: rendered event stream --}}
            <x-ui.card>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-sm font-medium text-ink">{{ __('Live console') }}</h3>
                        <p class="text-xs text-muted">{{ __('Renders events using the same visual structure as the chat console.') }}</p>
                    </div>
                </div>

                <div class="space-y-2 min-h-[200px]">
                    <template x-if="streamEntries.length === 0 && !streaming">
                        <div class="rounded-2xl border border-dashed border-border-default bg-surface-subtle px-4 py-6 text-sm text-muted">
                            {{ __('Select a turn and start the replay to see events rendered here.') }}
                        </div>
                    </template>

                    <template x-for="(entry, idx) in streamEntries" :key="idx">
                        <div x-show="
                            entry.type !== 'tool_call'
                            || !toolsCollapsed
                            || entry.status === 'running'
                        ">
                            {{-- Thinking --}}
                            <template x-if="entry.type === 'thinking'">
                                <div class="flex gap-2 py-1">
                                    <div class="shrink-0 mt-0.5">
                                        <x-icon name="heroicon-o-light-bulb" class="w-4 h-4 text-muted" />
                                    </div>
                                    <div class="flex flex-col gap-0.5">
                                        <div class="flex items-center gap-1.5 text-xs text-muted">
                                            <template x-if="entry.active">
                                                <span class="w-2 h-2 bg-accent rounded-full animate-pulse"></span>
                                            </template>
                                            <span>{{ __('Thinking…') }}</span>
                                        </div>
                                        <template x-if="entry.description">
                                            <div class="text-[11px] text-muted/70 italic" x-text="entry.description"></div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Tool call --}}
                            <template x-if="entry.type === 'tool_call'">
                                <div class="py-1">
                                    <div class="min-w-0">
                                        <div class="rounded-lg border border-border-default bg-surface-card px-2.5 py-1.5 text-xs">
                                            <div class="flex items-start gap-2">
                                                <span class="min-w-0 flex-1 font-medium text-ink break-words" x-text="entry.tool"></span>
                                                <span class="ml-auto flex items-center gap-1.5 shrink-0">
                                                    <template x-if="entry.status === 'running'">
                                                        <span class="w-2 h-2 bg-accent rounded-full animate-pulse"></span>
                                                    </template>
                                                    <template x-if="entry.durationMs !== undefined">
                                                        <span class="tabular-nums text-muted" x-text="(entry.durationMs / 1000).toFixed(1) + 's'"></span>
                                                    </template>
                                                    <template x-if="entry.status === 'success'">
                                                        <span class="inline-flex items-center rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[9px] font-medium text-emerald-700 dark:text-emerald-400">{{ __('Done') }}</span>
                                                    </template>
                                                    <template x-if="entry.status === 'error'">
                                                        <span class="inline-flex items-center rounded-full bg-red-500/10 px-1.5 py-0.5 text-[9px] font-medium text-red-600 dark:text-red-400">{{ __('Error') }}</span>
                                                    </template>
                                                </span>
                                            </div>
                                            <template x-if="entry.argsSummary">
                                                <div
                                                    class="mt-1 text-muted whitespace-pre-wrap break-all"
                                                    :class="entry.tool === 'bash' ? 'font-mono text-[11px]' : ''"
                                                    x-text="entry.argsSummary"
                                                ></div>
                                            </template>
                                            {{-- Live stdout while tool is running --}}
                                            <template x-if="entry.stdoutBuffer && entry.status === 'running'">
                                                <div class="mt-1.5 max-h-40 overflow-y-auto rounded bg-surface-inset px-2 py-1.5 font-mono text-[11px] text-muted whitespace-pre-wrap break-all border border-border-subtle">
                                                    <span x-text="entry.stdoutBuffer"></span>
                                                </div>
                                            </template>
                                            <template x-if="entry.errorPayload">
                                                <div class="mt-2 border-t border-border-default pt-2 space-y-1 text-red-500">
                                                    <div x-show="entry.errorPayload?.code"><span class="font-medium">{{ __('Code') }}:</span> <span x-text="entry.errorPayload?.code"></span></div>
                                                    <div x-text="entry.errorPayload?.message"></div>
                                                </div>
                                            </template>
                                            <template x-if="!entry.errorPayload && entry.resultPreview">
                                                <div class="mt-2 border-t border-border-default pt-2 max-h-24 overflow-y-auto font-mono text-[11px] text-muted whitespace-pre-wrap break-all">
                                                    <span x-text="entry.resultPreview"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            {{-- Assistant streaming output --}}
                            <template x-if="entry.type === 'assistant_streaming'">
                                <div class="flex justify-start py-1">
                                    <div class="max-w-[90%] rounded-2xl px-3 py-2 text-sm bg-surface-subtle text-ink">
                                        <div class="whitespace-pre-wrap break-words" x-text="entry.content"></div>
                                    </div>
                                </div>
                            </template>

                            {{-- Error --}}
                            <template x-if="entry.type === 'error'">
                                <div class="flex justify-start py-1">
                                    <div class="max-w-[90%] rounded-2xl px-3 py-2 text-sm bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20">
                                        <div class="flex items-center gap-1.5 mb-0.5">
                                            <x-icon name="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5" />
                                            <span class="text-[10px] font-semibold uppercase tracking-wider">{{ __('Error') }}</span>
                                        </div>
                                        <div class="whitespace-pre-wrap break-words" x-text="entry.message"></div>
                                    </div>
                                </div>
                            </template>

                            {{-- Hook action / denied --}}
                            <template x-if="entry.type === 'hook_action'">
                                <div class="flex gap-2 py-1">
                                    <x-icon name="heroicon-o-shield-exclamation" class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                                    <div class="text-xs text-amber-700 dark:text-amber-400">
                                        <span class="font-medium" x-text="entry.tool"></span>
                                        <span class="text-muted"> — </span>
                                        <span x-text="entry.reason"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Streaming indicator --}}
                    <template x-if="streaming && streamEntries.length === 0">
                        <div class="flex items-center gap-2 text-xs text-muted py-3">
                            <span class="w-2 h-2 bg-accent rounded-full animate-pulse"></span>
                            <span>{{ __('Waiting for events…') }}</span>
                        </div>
                    </template>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
