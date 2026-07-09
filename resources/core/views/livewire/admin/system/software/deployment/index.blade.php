<div>
    <x-slot name="title">{{ __('Updates') }}</x-slot>

    <div
        class="space-y-section-gap"
        wire:init="loadLatestStatus"
        x-data="{
            running: false,
            runLogOpen: false,
            dismissed: false,
            refreshing: false,
            refreshTimer: null,
            finishedStatus: @js(($runStatus ?? 'idle') !== 'idle' ? $runStatus : null),
            justRefreshed: false,
            storageKey: 'belimbing.deployment.run-log-after-refresh',
            init() {
                this.restoreAfterRefresh();
            },
            isFloating() {
                return this.runLogOpen && ! this.dismissed;
            },
            statusIs(status) {
                return this.finishedStatus === status;
            },
            openRunLog() {
                if (this.refreshTimer) {
                    window.clearTimeout(this.refreshTimer);
                    this.refreshTimer = null;
                }
                this.running = true;
                this.refreshing = false;
                this.finishedStatus = null;
                this.justRefreshed = false;
                this.runLogOpen = true;
                this.dismissed = false;
                this.forgetAfterRefresh();
            },
            finishRun(detail = {}) {
                this.running = false;
                this.finishedStatus = detail.status || this.finishedStatus || 'success';

                if (detail.refresh !== false) {
                    this.scheduleStatusRefresh();
                }
            },
            scheduleStatusRefresh() {
                if (this.refreshing) {
                    return;
                }

                this.refreshing = true;
                this.rememberAfterRefresh();
                this.refreshTimer = window.setTimeout(() => window.location.reload(), 1600);
            },
            closeRunLog() {
                this.dismissed = true;
                this.runLogOpen = false;
                this.justRefreshed = false;
                this.forgetAfterRefresh();
            },
            rememberAfterRefresh() {
                try {
                    window.sessionStorage.setItem(this.storageKey, JSON.stringify({
                        status: this.finishedStatus,
                        dismissed: this.dismissed,
                        at: Date.now(),
                    }));
                } catch (error) {
                    // Storage may be unavailable in hardened browser contexts.
                }
            },
            restoreAfterRefresh() {
                try {
                    const raw = window.sessionStorage.getItem(this.storageKey);

                    if (! raw) {
                        return;
                    }

                    window.sessionStorage.removeItem(this.storageKey);

                    const payload = JSON.parse(raw);

                    if (! payload?.at || Date.now() - payload.at > 300000) {
                        return;
                    }

                    this.running = false;
                    this.refreshing = false;
                    this.finishedStatus = payload.status || null;
                    this.justRefreshed = ! payload.dismissed;
                    this.runLogOpen = ! payload.dismissed;
                    this.dismissed = Boolean(payload.dismissed);
                } catch (error) {
                    this.forgetAfterRefresh();
                }
            },
            forgetAfterRefresh() {
                try {
                    window.sessionStorage.removeItem(this.storageKey);
                } catch (error) {
                    // Storage may be unavailable in hardened browser contexts.
                }
            },
        }"
        @run-finished.window="finishRun($event.detail || {})"
        @deployment-run-recorded="finishRun($event.detail || {})"
        @keydown.escape.window="closeRunLog()"
    >
        <x-ui.page-header
            :title="__('Updates')"
            :subtitle="__('Pull the latest code per Distribution Bundle and schedule the runtime reload. Each bundle updates from its branch, migrations run under maintenance mode, then BLB records the worker reload separately when the background command finishes.')"
        />

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        {{-- This page is excepted from maintenance mode, so it stays reachable even when
             a run was interrupted before it could lift maintenance. Surface that state and
             let the operator bring the site back online without dropping to a shell. --}}
        @if ($maintenanceActive)
            <x-ui.alert variant="danger">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ __('The site is in maintenance mode.') }}</p>
                        <p class="mt-1 text-sm">{{ __('Visitors currently see a 503 page — an update may have been interrupted before it could finish. Bring the site back online once the deployment is in a good state.') }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.system.software.online') }}" class="shrink-0">
                        @csrf
                        <x-ui.button type="submit" variant="primary">{{ __('Bring back online') }}</x-ui.button>
                    </form>
                </div>
            </x-ui.alert>
        @endif

        @if ($checkFailures !== [])
            <x-ui.alert variant="warning">
                {{ __('Could not check latest commits for these Distribution Bundles: :bundles. Public GitHub repositories do not need a token; see the Latest column for the Git response. If one of these repositories is private, add its owner token in', ['bundles' => implode(', ', $checkFailures)]) }}
                <a href="{{ route('admin.system.software.github-access.index') }}" class="font-medium underline" wire:navigate>{{ __('GitHub Access') }}</a>.
            </x-ui.alert>
        @endif

        <x-ui.card>
            <div x-data="{ helpOpen: false }" class="mb-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="inline-flex max-w-full items-center gap-2">
                            <h2 class="text-base font-medium text-ink">{{ __('Distribution Bundles') }}</h2>
                            <x-ui.help @click="helpOpen = ! helpOpen" ::aria-expanded="helpOpen" />
                        </div>
                        <p class="mt-1 text-sm text-muted">{{ __('Update pulls the selected bundles, installs changed PHP dependencies (or refreshes the autoloader), builds frontend assets, runs migrations, then schedules a worker reload and records its final result separately. Private repositories use the token set in') }}
                            <a href="{{ route('admin.system.software.github-access.index') }}" class="font-medium underline" wire:navigate>{{ __('GitHub Access') }}</a>.</p>
                    </div>
                    <div class="ml-auto flex shrink-0 flex-wrap justify-end gap-2">
                        <x-ui.button type="button" variant="primary" wire:click="updateAll" x-on:click="openRunLog()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing || @js(! $behind)" :disabled="! $behind">
                            <span wire:loading.remove wire:target="updateAll">{{ __('Update all') }}</span>
                            <span wire:loading wire:target="updateAll">{{ __('Updating…') }}</span>
                        </x-ui.button>
                    </div>
                </div>

                <div
                    x-cloak
                    x-show="helpOpen"
                    x-transition:enter="transition-all ease-out duration-200 motion-reduce:duration-0"
                    x-transition:enter-start="max-h-0 opacity-0"
                    x-transition:enter-end="max-h-96 opacity-100"
                    x-transition:leave="transition-all ease-in duration-150 motion-reduce:duration-0"
                    x-transition:leave-start="max-h-96 opacity-100"
                    x-transition:leave-end="max-h-0 opacity-0"
                    class="mt-3 overflow-hidden rounded-2xl border border-border-default bg-surface-subtle text-sm text-muted"
                    @click="helpOpen = false"
                    role="note"
                    aria-label="{{ __('Click to dismiss') }}"
                >
                    <div class="p-4">
                        <p>{{ __('A Distribution Bundle is BLB\'s installable, versioned code bundle. Each bundle lands at a known path and namespace, such as the Belimbing platform, a domain, a slot, or an extension. Today, bundles are delivered as Git repositories.') }}</p>
                    </div>
                </div>
            </div>

            <x-ui.table container="flush" :caption="__('Deployment Distribution Bundles')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Distribution Bundle') }}</x-ui.th>
                        <x-ui.th>{{ __('Branch') }}</x-ui.th>
                        <x-ui.th>{{ __('Current') }}</x-ui.th>
                        <x-ui.th>{{ __('Latest') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Status') }}</x-ui.th>
                    </tr>
                </x-slot>

                @foreach ($status as $s)
                    <tr wire:key="dist-{{ $s['key'] }}">
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            <div class="text-sm font-medium text-ink">{{ $s['label'] }}</div>
                            <div class="font-mono text-xs text-muted">{{ $s['repo'] ?? $s['path'] }}</div>
                            @if ($s['working_tree']['dirty'] > 0 || $s['working_tree']['ahead'] > 0)
                                {{-- Uncommitted/unpushed changes inside a bundle's own nested repo never show in the platform repo's git status; surface them here so a tool that wrote into the bundle (e.g. schema incubation) can't leave the operator in the dark. --}}
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    @if ($s['working_tree']['dirty'] > 0)
                                        <x-ui.badge variant="warning" :title="__('Uncommitted changes in this bundle — commit them in its repository.')">{{ trans_choice('{1} :count uncommitted change|[2,*] :count uncommitted changes', (int) $s['working_tree']['dirty'], ['count' => $s['working_tree']['dirty']]) }}</x-ui.badge>
                                    @endif
                                    @if ($s['working_tree']['ahead'] > 0)
                                        <x-ui.badge variant="warning" :title="__('Local commits not yet pushed to this bundle\'s remote.')">{{ trans_choice('{1} :count unpushed commit|[2,*] :count unpushed commits', (int) $s['working_tree']['ahead'], ['count' => $s['working_tree']['ahead']]) }}</x-ui.badge>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-sm text-muted">{{ $s['branch'] ?? '—' }}</td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            @if ($s['current'])
                                <span class="font-mono text-sm text-ink">{{ $s['current']['short'] }}</span>
                                <div class="text-xs text-muted">{{ $s['current']['ago'] }}</div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            @if ($s['latest'])
                                <span class="font-mono text-sm text-ink">{{ $s['latest']['short'] }}</span>
                                <div class="text-xs text-muted">{{ $s['latest']['ago'] }}</div>
                            @elseif ($s['error'] === null && ! $latestStatusLoaded)
                                <span class="inline-flex items-center gap-1.5 text-xs text-muted">
                                    <x-icon name="heroicon-o-arrow-path" class="h-3.5 w-3.5 animate-spin" />
                                    {{ __('Checking latest…') }}
                                </span>
                            @else
                                <span class="text-xs text-muted">{{ $s['error'] }}</span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-right">
                            @if ($s['error'] === null && ! $latestStatusLoaded)
                                <x-ui.badge variant="info">{{ __('Checking') }}</x-ui.badge>
                            @elseif ($s['up_to_date'] === true)
                                <x-ui.badge variant="success">{{ __('Up to date') }}</x-ui.badge>
                            @elseif ($s['up_to_date'] === false)
                                <x-ui.button type="button" size="sm" variant="primary" wire:click="updateRepo('{{ $s['key'] }}')" x-on:click="openRunLog()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing" wire:target="updateRepo('{{ $s['key'] }}')">
                                    <span wire:loading.remove wire:target="updateRepo('{{ $s['key'] }}')">{{ __('Update') }}</span>
                                    <span wire:loading wire:target="updateRepo('{{ $s['key'] }}')">{{ __('Updating…') }}</span>
                                </x-ui.button>
                            @else
                                <x-ui.badge variant="warning">{{ __('Unknown') }}</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card>
            @php
                $reloadStateStatus = is_array($reloadState ?? null) ? ($reloadState['status'] ?? null) : null;
                $reloadInProgress = in_array($reloadStateStatus, ['pending', 'running'], true);
            @endphp

            <h2 class="text-base font-medium text-ink">{{ __('Maintenance') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Each of these runs as part of Update. Trigger one on its own to apply a dependency, asset, or worker change — or to recover from a failed run — without pulling code.') }}</p>

            <div class="mt-4 space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-medium text-ink">{{ __('PHP dependencies') }}</h3>
                            <span class="font-mono text-xs text-muted">composer install</span>
                            @if ($lastComposerRun !== null)
                                <x-ui.badge :variant="$lastComposerRun['ok'] ? 'success' : 'warning'">
                                    {{ $lastComposerRun['ok'] ? __('OK') : __('Needs attention') }}
                                </x-ui.badge>
                            @endif
                        </div>
                        @if ($lastComposerRun !== null)
                            <p class="mt-1 text-xs text-muted">
                                {{ __('Last run') }} <x-ui.datetime :value="$lastComposerRun['attempted_at']" /> · {{ $lastComposerRun['message'] }}
                            </p>
                        @else
                            <p class="mt-1 text-xs text-muted">{{ __('No composer install has been recorded yet.') }}</p>
                        @endif
                    </div>
                    <x-ui.button type="button" variant="outline" class="ml-auto shrink-0" wire:click="rebuildPhp" x-on:click="openRunLog()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing" wire:target="rebuildPhp">
                        <span wire:loading.remove wire:target="rebuildPhp">{{ __('Install PHP dependencies') }}</span>
                        <span wire:loading wire:target="rebuildPhp">{{ __('Running composer install…') }}</span>
                    </x-ui.button>
                </div>

                <div class="flex flex-wrap items-start justify-between gap-3 border-t border-border-default pt-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-medium text-ink">{{ __('Frontend assets') }}</h3>
                            <span class="font-mono text-xs text-muted">{{ $packageManager }} install &amp;&amp; {{ $packageManager }} run build</span>
                            @if ($lastFrontendRun !== null)
                                <x-ui.badge :variant="$lastFrontendRun['ok'] ? 'success' : 'warning'">
                                    {{ $lastFrontendRun['ok'] ? __('OK') : __('Needs attention') }}
                                </x-ui.badge>
                            @endif
                        </div>
                        @if ($lastFrontendRun !== null)
                            <p class="mt-1 text-xs text-muted">
                                {{ __('Last run') }} <x-ui.datetime :value="$lastFrontendRun['attempted_at']" /> · {{ $lastFrontendRun['message'] }}
                            </p>
                        @else
                            <p class="mt-1 text-xs text-muted">{{ __('No frontend build has been recorded yet.') }}</p>
                        @endif
                    </div>
                    <x-ui.button type="button" variant="outline" class="ml-auto shrink-0" wire:click="rebuildAssets" x-on:click="openRunLog()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing" wire:target="rebuildAssets">
                        <span wire:loading.remove wire:target="rebuildAssets">{{ __('Build frontend assets') }}</span>
                        <span wire:loading wire:target="rebuildAssets">{{ __('Running :pm install & build…', ['pm' => $packageManager]) }}</span>
                    </x-ui.button>
                </div>

                <div class="flex flex-wrap items-start justify-between gap-3 border-t border-border-default pt-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-medium text-ink">{{ __('FrankenPHP workers') }}</h3>
                            @if ($reloadInProgress)
                                <x-ui.badge variant="warning">
                                    {{ $reloadStateStatus === 'running' ? __('Reload running') : __('Reload pending') }}
                                </x-ui.badge>
                            @endif
                            @if ($lastReload !== null)
                                <x-ui.badge :variant="$lastReload['ok'] ? 'success' : 'warning'">
                                    {{ $lastReload['ok'] ? __('Workers reloaded') : __('Needs attention') }}
                                </x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-muted">{{ __('Schedules web workers to respawn through the FrankenPHP/Caddy admin API and signals queue workers to restart — it does not pull code, install dependencies, build assets, or run migrations. Use it when deployed code is already in place but running workers may still serve old PHP state.') }}</p>
                        @if ($lastReload !== null)
                            <p class="mt-1 text-xs text-muted">
                                {{ __('Last run') }} <x-ui.datetime :value="$lastReload['attempted_at']" /> · {{ $lastReload['message'] }}
                                <span class="font-mono">({{ $lastReload['admin_url'] }})</span>
                            </p>
                        @else
                            <p class="mt-1 text-xs text-muted">{{ __('No reload has been recorded yet.') }}</p>
                        @endif
                        @if ($reloadInProgress && is_array($reloadState ?? null))
                            <p class="mt-1 text-xs text-status-warning">
                                {{ __('Current reload') }} <x-ui.datetime :value="$reloadState['attempted_at']" /> · {{ $reloadState['message'] }}
                            </p>
                        @endif
                    </div>
                    <x-ui.button
                        type="button"
                        variant="outline"
                        class="ml-auto shrink-0"
                        wire:click="reloadOnly"
                        x-on:click="if (@js(app()->environment('production')) && ! window.confirm(@js(__('Reloading FrankenPHP restarts web workers and may briefly interrupt active requests. Continue?')))) { $event.preventDefault(); $event.stopImmediatePropagation(); return; } openRunLog()"
                        wire:loading.attr="disabled"
                        x-bind:disabled="running || refreshing || @js($reloadInProgress)"
                        :disabled="$reloadInProgress"
                        wire:target="reloadOnly"
                    >
                        <span wire:loading.remove wire:target="reloadOnly">{{ $reloadInProgress ? __('Reload pending') : __('Reload FrankenPHP') }}</span>
                        <span wire:loading wire:target="reloadOnly">{{ __('Reloading…') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>

        {{-- Run log: floats as a modal from when a run starts until the operator closes it; it then docks to rest inline at the end of the page. It never floats on a plain page visit. --}}
        <div
            :class="isFloating() ? 'fixed inset-0 z-50 overflow-y-auto' : ''"
            x-bind:role="isFloating() ? 'dialog' : null"
            x-bind:aria-modal="isFloating() ? 'true' : null"
            aria-labelledby="deployment-run-log-title"
        >
            <div x-show="isFloating()" x-cloak style="display: none;" class="fixed inset-0 bg-black/50" @click="closeRunLog()"></div>

            <div :class="isFloating() ? 'relative z-10 flex min-h-full items-start justify-center p-4 sm:items-center' : ''">
                <div :class="isFloating() ? 'w-full max-w-2xl shadow-2xl' : ''">
                    <x-ui.card>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 id="deployment-run-log-title" class="text-base font-medium text-ink">
                                        <span x-show="running">{{ __('Run in progress') }}</span>
                                        <span x-show="! running && refreshing">{{ __('Run finished') }}</span>
                                        <span x-show="! running && ! refreshing && statusIs('pending')">{{ __('Reload pending') }}</span>
                                        <span x-show="! running && ! refreshing && justRefreshed">{{ __('Run complete') }}</span>
                                        <span x-show="! running && ! refreshing && ! justRefreshed && ! statusIs('pending')">{{ __('Last run') }}</span>
                                    </h2>

                                    <x-ui.badge variant="info" x-show="running" x-cloak>
                                        <x-icon name="heroicon-o-arrow-path" class="mr-1 h-3.5 w-3.5 animate-spin" />
                                        {{ __('Running') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="success" x-show="! running && refreshing && statusIs('success')" x-cloak>
                                        {{ __('Complete') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="warning" x-show="! running && refreshing && statusIs('warning')" x-cloak>
                                        {{ __('Warnings') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="danger" x-show="! running && refreshing && statusIs('error')" x-cloak>
                                        {{ __('Needs action') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="warning" x-show="! running && statusIs('pending')" x-cloak>
                                        {{ __('Reload pending') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="info" x-show="refreshing && ! running" x-cloak>
                                        <x-icon name="heroicon-o-arrow-path" class="mr-1 h-3.5 w-3.5 animate-spin" />
                                        {{ __('Refreshing table') }}
                                    </x-ui.badge>
                                    @if ($runStatus !== 'idle' && $runStatus !== 'pending')
                                        <x-ui.badge :variant="$runVariant" x-show="! running && ! refreshing">{{ $runLabel }}</x-ui.badge>
                                    @endif
                                </div>

                                <p class="mt-1 text-xs text-muted" x-show="running" x-cloak>{{ __('Streaming live output. You can dismiss this window; the run continues.') }}</p>
                                <p class="mt-1 text-xs text-muted" x-show="refreshing && ! running" x-cloak>{{ __('Run log saved. Reloading this page so commits and actions match the code on disk.') }}</p>
                                <p class="mt-1 text-xs text-muted" x-show="statusIs('pending') && ! running && ! refreshing" x-cloak>{{ __('Runtime reload has been scheduled. BLB will record the final reload result in the Maintenance section when the background command finishes.') }}</p>
                                <p class="mt-1 text-xs text-muted" x-show="justRefreshed && ! running && ! refreshing" x-cloak>{{ __('Status refreshed. Current commits and actions now reflect the code on disk.') }}</p>
                                @if ($runAt)
                                    <p class="mt-1 text-xs text-muted" x-show="! running && ! refreshing">
                                        {{ __('Last run') }} <x-ui.datetime :value="$runAt" />@if ($runSummary !== '') · {{ $runSummary }}@endif
                                    </p>
                                @else
                                    <p class="mt-1 text-xs text-muted" x-show="! running && ! refreshing">{{ __('No update has run yet.') }}</p>
                                @endif
                            </div>

                            {{-- Close only dismisses the floating shell; an in-flight backend run keeps going. --}}
                            <button
                                type="button"
                                x-show="isFloating()"
                                x-on:click="closeRunLog()"
                                class="rounded-md text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent"
                                aria-label="{{ __('Dismiss run log') }}"
                            >
                                <x-icon name="heroicon-o-x-mark" class="h-5 w-5" />
                            </button>
                        </div>

                        <div
                            x-data="{
                                markerSeen: false,
                                scrollToEnd() {
                                    this.$nextTick(() => { this.$el.scrollTop = this.$el.scrollHeight });
                                },
                                detectRecordedRun() {
                                    if (! this.running || this.markerSeen) {
                                        return;
                                    }

                                    const marker = this.$el.querySelector('[data-deployment-run-recorded]');

                                    if (! marker) {
                                        return;
                                    }

                                    this.markerSeen = true;
                                    this.$dispatch('deployment-run-recorded', { status: marker.dataset.runOutcome || null, refresh: true });
                                },
                                init() {
                                    this.scrollToEnd();
                                    this.detectRecordedRun();
                                    this.observer = new MutationObserver(() => {
                                        this.scrollToEnd();
                                        this.detectRecordedRun();
                                    });
                                    this.observer.observe(this.$el, { childList: true, subtree: true, characterData: true });
                                },
                                destroy() {
                                    this.observer?.disconnect();
                                },
                            }"
                            x-show="runLogOpen || running || @js($displayLog !== [])"
                            x-cloak
                            class="mt-2 h-72 overflow-y-auto rounded-md bg-surface-subtle px-3 py-2 font-mono text-[11px] leading-5 text-ink"
                            aria-live="polite"
                        >
                            <div class="space-y-0" wire:stream="runLog">
                                @foreach ($displayLog as $line)
                                    <div class="{{ $this->runLineClass($line) }}">{{ $line }}</div>
                                @endforeach
                                @if ($runStatus !== 'idle' && $displayLog !== [])
                                    <span class="hidden" aria-hidden="true" data-deployment-run-recorded="true" data-run-outcome="{{ $runStatus }}"></span>
                                @endif
                            </div>
                        </div>
                    </x-ui.card>
                </div>
            </div>
        </div>
    </div>
</div>
