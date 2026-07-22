<div>
    <x-slot name="title">{{ __('Updates') }}</x-slot>

    <div
        class="space-y-section-gap"
        @if (! $maintenanceActive && ! $updateInProgress) wire:init="loadLatestStatus" @endif
        x-data="{
            running: false,
            runLogOpen: false,
            dismissed: false,
            refreshing: false,
            refreshTimer: null,
            finishedStatus: @js(($runStatus ?? 'idle') !== 'idle' ? $runStatus : null),
            justRefreshed: false,
            reloadInProgress: @js($reloadInProgress),
            updateInProgress: @js($updateInProgress),
            maintenanceActive: @js($maintenanceActive),
            progressUrl: @js(route('admin.system.software.updates.progress')),
            _pollTimer: null,
            _pollFailures: 0,
            _reloadRetries: 0,
            _destroyed: false,
            _livewire503Guard: null,
            reloadRequiresConfirmation: @js(app()->environment('production')),
            reloadConfirmationMessage: @js(__('Reloading FrankenPHP restarts web workers and may briefly interrupt active requests. Continue?')),
            storageKey: 'belimbing.deployment.run-log-after-refresh',
            init() {
                this.restoreAfterRefresh();

                {{-- Livewire renders 503 maintenance responses (and 500s during
                    worker restart) in a modal overlay via showHtmlModal. During a
                    software update, any Livewire call to a non-exempt route —
                    wire:navigate prefetches, stray clicks, morph-triggered requests
                    — 503s or 500s and flashes an error page over the progress modal.
                    Suppress the modal unconditionally for 503/500: this page has its
                    own progress UI and never benefits from Livewire's generic error
                    modal. Checking maintenanceActive here is useless — it is a
                    one-time @js() snapshot from page load, typically false because
                    the page loads before the update enters maintenance, so the guard
                    would never fire. The progress poller uses the exempt progress
                    route and is unaffected. --}}
                this._livewire503Guard = window.Livewire?.hook('request', ({ fail }) => {
                    fail(({ status, preventDefault }) => {
                        if (status === 503 || status === 500) {
                            preventDefault();
                        }
                    });
                });

                if (this.updateInProgress && ! ['success', 'warning', 'error'].includes(this.finishedStatus)) {
                    this.followDetachedRun();
                }
            },
            destroy() {
                this._destroyed = true;
                window.clearTimeout(this._pollTimer);
                window.clearTimeout(this.refreshTimer);
                if (this._livewire503Guard) {
                    this._livewire503Guard();
                }
            },
            {{-- Detached updates run outside the web workers and append every
                 line to the durable run record. Livewire's endpoint 503s while
                 the update holds the site in maintenance mode, so we follow the
                 run through the maintenance-excepted progress route instead of
                 wire:poll — and instead of the old flickering 5s full reload. --}}
            followDetachedRun() {
                if (this._pollTimer !== null) {
                    return;
                }

                this.openRunLog();
                this.pollProgressSoon(0);
            },
            pollProgressSoon(delay = 2000) {
                if (this._destroyed) {
                    return;
                }

                this._pollTimer = window.setTimeout(() => this.pollProgress(), delay);
            },
            async pollProgress() {
                if (this._destroyed) {
                    return;
                }

                try {
                    const response = await fetch(this.progressUrl, { headers: { 'Accept': 'application/json' } });

                    if (! response.ok) {
                        throw new Error(`progress poll failed with status ${response.status}`);
                    }

                    const run = await response.json();
                    if (this._destroyed) {
                        return;
                    }
                    this._pollFailures = 0;
                    this.renderRunProgress(run);

                    if (['success', 'warning', 'error'].includes(run.status)) {
                        this._pollTimer = null;

                        return; {{-- the recorded-run marker takes over from here --}}
                    }
                } catch (error) {
                    {{-- Transient failures are by design: the final phase reloads
                         the web workers, which briefly drops requests. Keep
                         polling, and only fall back to a full reload if the feed
                         stays unreachable for ~90s. --}}
                    if (this._destroyed) {
                        return;
                    }
                    if (++this._pollFailures >= 45) {
                        window.location.reload();

                        return;
                    }
                }

                this.pollProgressSoon();
            },
            renderRunProgress(run) {
                const target = this.$root.querySelector('[data-run-log-lines]');

                if (! target || ! Array.isArray(run.lines)) {
                    return;
                }

                const fragment = document.createDocumentFragment();

                for (const line of run.lines) {
                    const div = document.createElement('div');
                    div.textContent = line.text ?? '';

                    if (line.class) {
                        div.className = line.class;
                    }

                    fragment.appendChild(div);
                }

                {{-- Terminal runs get the same hidden marker the Livewire stream
                     emits; the run box's MutationObserver spots it and finishes
                     the run through the existing deployment-run-recorded flow. --}}
                if (['success', 'warning', 'error'].includes(run.status)) {
                    const marker = document.createElement('span');
                    marker.className = 'hidden';
                    marker.setAttribute('aria-hidden', 'true');
                    marker.dataset.deploymentRunRecorded = 'true';
                    marker.dataset.runOutcome = run.status;
                    fragment.appendChild(marker);
                }

                target.replaceChildren(fragment);
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
                this._reloadRetries = 0;
                this.refreshTimer = window.setTimeout(() => this.reloadWhenHealthy(), 500);
            },
            {{-- The post-run reload refreshes the status table to match the code on
                 disk. But the run's final phase just restarted the FrankenPHP workers,
                 and they may still be settling — a blind window.location.reload() would
                 hit a 500 and show the browser's error page. Probe the exempt progress
                 route first; only reload once a worker is actually serving responses.
                 Fall back to a direct reload after ~15s so the operator is never stuck
                 in a JS loop if the server is truly down. --}}
            async reloadWhenHealthy() {
                if (this._destroyed) {
                    return;
                }

                try {
                    const response = await fetch(this.progressUrl, {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (response.ok) {
                        window.location.reload();

                        return;
                    }
                } catch (error) {
                    {{-- Workers still restarting — keep waiting. --}}
                }

                if (this._destroyed) {
                    return;
                }

                if (++this._reloadRetries >= 10) {
                    window.location.reload();

                    return;
                }

                this.refreshTimer = window.setTimeout(() => this.reloadWhenHealthy(), 1500);
            },
            closeRunLog() {
                this.dismissed = true;
                this.runLogOpen = false;
                this.justRefreshed = false;
                this.forgetAfterRefresh();
            },
            confirmWorkerReload(event) {
                if (this.reloadRequiresConfirmation && ! window.confirm(this.reloadConfirmationMessage)) {
                    event.preventDefault();
                    event.stopImmediatePropagation();

                    return;
                }

                this.openRunLog();
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
        @follow-update-progress.window="followDetachedRun()"
        @deployment-run-recorded="finishRun($event.detail || {})"
        @keydown.escape.window="closeRunLog()"
    >
        <x-ui.page-header
            :title="__('Updates')"
            :subtitle="__('Launch a durable background update per Distribution Bundle. The detached process survives web-worker restarts, runs migrations under maintenance mode, reloads the runtime, and records its progress here.')"
        />

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
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

        {{-- FrankenPHP loads PHP extensions once, at OS-process startup. If php.ini
             was edited after this process started, "Reload FrankenPHP" below cannot
             pick up the change — it only re-executes the worker script, not PHP's
             module init. Surface that distinctly so operators do not waste a reload
             on it, and direct the restart through the host that owns the process. --}}
        @if ($missingExtensions !== [])
            <x-ui.alert variant="danger">
                <div class="w-full space-y-3">
                    <div>
                        <p class="font-medium">
                            {{ trans_choice(':count PHP extension enabled in php.ini is not loaded in the running process|:count PHP extensions enabled in php.ini are not loaded in the running process', count($missingExtensions), ['count' => count($missingExtensions)]) }}
                        </p>
                        <p class="mt-1 font-mono text-xs">{{ implode(', ', $missingExtensions) }}</p>
                        <p class="mt-1 text-sm">{{ __('Reloading FrankenPHP workers will not fix this — extensions load once when the process starts. Restart FrankenPHP from the host after any software update or maintenance action finishes.') }}</p>
                    </div>

                    <x-ui.disclosure
                        :title="__('Restart from the host')"
                        variant="card-header"
                        content-class="mt-2 max-w-prose space-y-2 text-sm"
                    >
                        @if (app()->environment('local'))
                            <p>{{ __('Stop the development launcher with Ctrl+C, then start it again from the project directory:') }}</p>
                            <p class="inline-block rounded bg-surface-subtle px-input-x py-input-y font-mono text-xs text-ink">
                                {{ PHP_OS_FAMILY === 'Windows' ? '.\\scripts\\start-app.ps1' : './scripts/start-app.sh' }}
                            </p>
                        @else
                            <p>{{ __('Restart the application service with the supervisor configured for this deployment, such as Task Scheduler, systemd, or the container platform. This page does not stop the process because it cannot verify that an external supervisor will bring it back.') }}</p>
                            <p>{{ __('Return here after the service is healthy. This warning disappears when the new process has loaded the configured extensions.') }}</p>
                        @endif
                    </x-ui.disclosure>
                </div>
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
                        <p class="mt-1 text-sm text-muted">{{ __('Update launches a detached process that pulls the selected bundles, installs changed PHP dependencies (or refreshes the autoloader), builds frontend assets, runs migrations, and reloads workers. Private repositories use the token set in') }}
                            <a href="{{ route('admin.system.software.github-access.index') }}" class="font-medium underline" wire:navigate>{{ __('GitHub Access') }}</a>.</p>
                    </div>
                    <div class="ml-auto flex shrink-0 flex-wrap justify-end gap-2">
                        <x-ui.button type="button" variant="primary" wire:click="updateAll" x-on:click="openRunLog()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing || updateInProgress || maintenanceActive || ! $wire.behind">
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
                            @elseif ($s['error'] === null && ! $latestStatusLoaded && ! $maintenanceActive && ! $updateInProgress)
                                <span class="inline-flex items-center gap-1.5 text-xs text-muted">
                                    <x-icon name="heroicon-o-arrow-path" class="h-3.5 w-3.5 animate-spin" />
                                    {{ __('Checking latest…') }}
                                </span>
                            @elseif ($s['error'] === null && ! $latestStatusLoaded && ($maintenanceActive || $updateInProgress))
                                <span class="text-xs text-muted">—</span>
                            @else
                                <span class="text-xs text-muted">{{ $s['error'] }}</span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-right">
                            @if ($s['error'] === null && ! $latestStatusLoaded && ! $maintenanceActive && ! $updateInProgress)
                                <x-ui.badge variant="info">{{ __('Checking') }}</x-ui.badge>
                            @elseif ($s['error'] === null && ! $latestStatusLoaded && ($maintenanceActive || $updateInProgress))
                                <span class="text-xs text-muted">—</span>
                            @elseif ($s['up_to_date'] === true)
                                <x-ui.badge variant="success">{{ __('Up to date') }}</x-ui.badge>
                            @elseif ($s['up_to_date'] === false)
                                <x-ui.button type="button" size="sm" variant="primary" wire:click="updateRepo('{{ $s['key'] }}')" x-on:click="openRunLog()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing || updateInProgress || maintenanceActive" wire:target="updateRepo('{{ $s['key'] }}')">
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
                    <x-ui.button type="button" variant="outline" class="ml-auto shrink-0" wire:click="rebuildPhp" x-on:click="openRunLog()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing || updateInProgress || maintenanceActive" wire:target="rebuildPhp">
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
                    <x-ui.button type="button" variant="outline" class="ml-auto shrink-0" wire:click="rebuildAssets" x-on:click="openRunLog()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing || updateInProgress || maintenanceActive" wire:target="rebuildAssets">
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
                            @elseif ($reloadStateStalled)
                                <x-ui.badge variant="danger">
                                    {{ __('Reload stalled') }}
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
                        @if (($reloadInProgress || $reloadStateStalled) && is_array($reloadState ?? null))
                            <p class="mt-1 text-xs {{ $reloadStateStalled ? 'text-status-danger' : 'text-status-warning' }}">
                                {{ __('Current reload') }} <x-ui.datetime :value="$reloadState['attempted_at']" /> · {{ $reloadState['message'] }}
                            </p>
                        @endif
                    </div>
                    <x-ui.button
                        type="button"
                        variant="outline"
                        class="ml-auto shrink-0"
                        wire:click="reloadOnly"
                        x-on:click="confirmWorkerReload($event)"
                        wire:loading.attr="disabled"
                        x-bind:disabled="running || refreshing || updateInProgress || maintenanceActive || reloadInProgress"
                        :disabled="$reloadInProgress"
                        wire:target="reloadOnly"
                    >
                        <span wire:loading.remove wire:target="reloadOnly">
                            {{ $reloadInProgress ? __('Reload pending') : ($reloadStateStalled ? __('Retry reload') : __('Reload FrankenPHP')) }}
                        </span>
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
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 id="deployment-run-log-title" class="text-base font-medium text-ink">
                                        <span x-show="running">{{ __('Run in progress') }}</span>
                                        <span x-show="! running && refreshing">{{ __('Run finished') }}</span>
                                        <span x-show="! running && ! refreshing && statusIs('pending')">{{ __('Run in progress') }}</span>
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
                                        {{ __('In progress') }}
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
                                <p class="mt-1 text-xs text-muted" x-show="statusIs('pending') && ! running && ! refreshing" x-cloak>{{ __('Background work is still running. BLB will refresh this page and record the final result when it finishes.') }}</p>
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
                            <div class="space-y-0" wire:stream="runLog" data-run-log-lines>
                                @foreach ($displayLog as $line)
                                    <div class="{{ $this->runLineClass($line) }}">{{ $line }}</div>
                                @endforeach
                                {{-- Only terminal runs carry the recorded marker. A pending run is
                                    not recorded yet, and rendering a marker for it lets the
                                    MutationObserver fire detectRecordedRun prematurely (during the
                                    updateAll morph), setting markerSeen=true before the real
                                    terminal marker arrives — so finishRun never fires and the
                                    "Running" badge sticks on a completed run. --}}
                                @if (in_array($runStatus, ['success', 'warning', 'error'], true) && $displayLog !== [])
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
