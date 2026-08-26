<div>
    <x-slot name="title">{{ __('Updates') }}</x-slot>

    @php
        // Wall-clock budgets for the two async feeds below. Rendered into the
        // Alpine component so the timers and the operator-facing copy can never
        // drift apart. Both budgets are deadlines, not retry counts: a request
        // that hangs instead of failing must still exhaust them.
        $statusRefreshTimeoutSeconds = 15;
        $progressStallSeconds = 90;
    @endphp

    <div
        class="space-y-section-gap"
        @if (! $maintenanceActive && ! $updateInProgress) wire:init="loadLatestStatus" @endif
        x-data="{
            running: false,
            runLogOpen: false,
            dismissed: false,
            refreshing: false,
            refreshTimer: null,
            refreshWatchdog: null,
            refreshDeadline: null,
            refreshLastFailure: '',
            refreshTimeoutMs: @js($statusRefreshTimeoutSeconds * 1000),
            progressStallMs: @js($progressStallSeconds * 1000),
            {{-- A run that finishes but never gets confirmed is a failure, not a
                 quiet no-op: both feeds below surface it here instead of firing a
                 blind reload into a server that may not be answering. --}}
            contactLost: false,
            contactLostBadge: '',
            contactLostMessage: '',
            contactLostDetail: '',
            refreshTimeoutBadge: @js(__('Page not refreshed')),
            refreshTimeoutMessage: @js(__('The run itself finished and its log is recorded, but the site did not answer within :seconds seconds afterwards, so this page was never refreshed. Web workers may still be restarting, or the runtime failed to boot. Everything shown outside this window is stale until the page reloads.', ['seconds' => $statusRefreshTimeoutSeconds])),
            progressStallBadge: @js(__('Lost contact')),
            progressStallMessage: @js(__('The server stopped answering for :seconds seconds while the run was still going. The detached process may well have carried on without us — reload to read the recorded result.', ['seconds' => $progressStallSeconds])),
            finishedStatus: @js(($runStatus ?? 'idle') !== 'idle' ? $runStatus : null),
            justRefreshed: false,
            reloadInProgress: @js($reloadInProgress),
            updateInProgress: @js($updateInProgress),
            maintenanceActive: @js($maintenanceActive),
            progressUrl: @js(route('admin.system.software.updates.progress')),
            _pollTimer: null,
            _pollFailingSince: null,
            _destroyed: false,
            _livewire503Guard: null,
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
                window.clearTimeout(this.refreshWatchdog);
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
                    const response = await fetch(this.progressUrl, {
                        headers: { 'Accept': 'application/json' },
                        signal: this.abortAfter(10000),
                    });

                    if (! response.ok) {
                        throw new Error(`progress poll failed with status ${response.status}`);
                    }

                    const run = await response.json();
                    if (this._destroyed) {
                        return;
                    }
                    this._pollFailingSince = null;
                    this.renderRunProgress(run);

                    if (['success', 'warning', 'error'].includes(run.status)) {
                        this._pollTimer = null;

                        return; {{-- the recorded-run marker takes over from here --}}
                    }
                } catch (error) {
                    {{-- Transient failures are by design: the final phase reloads
                         the web workers, which briefly drops requests. Keep
                         polling, and report a stall only once the feed has stayed
                         unreachable for the whole budget. The budget is measured
                         in wall-clock rather than consecutive failures because a
                         request that hangs never comes back to be counted — the
                         abort signal above is what forces it to settle. --}}
                    if (this._destroyed) {
                        return;
                    }

                    this._pollFailingSince ??= Date.now();

                    if (Date.now() - this._pollFailingSince >= this.progressStallMs) {
                        this._pollTimer = null;
                        this.reportContactLost(this.progressStallBadge, this.progressStallMessage, this.describeFetchFailure(error));

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
                this.clearRefreshTimers();
                this.running = true;
                this.refreshing = false;
                this.clearContactLost();
                this._pollFailingSince = null;
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
                this.clearContactLost();
                this.rememberAfterRefresh();
                this.refreshDeadline = Date.now() + this.refreshTimeoutMs;

                {{-- Held on the component, not in reloadWhenHealthy: whichever path
                     ends up reporting the stall must be able to name the last real
                     reason, and a probe that hangs never settles to supply one. --}}
                this.refreshLastFailure = 'the site never answered a health probe';
                this.refreshTimer = window.setTimeout(() => this.reloadWhenHealthy(), 500);

                {{-- Independent of the probe chain on purpose. The deadline check
                     inside reloadWhenHealthy only runs between attempts, so it
                     cannot fire while an attempt is parked on a pending promise —
                     which is exactly the case that used to leave this page
                     spinning forever. This timer answers to nothing but the clock. --}}
                this.refreshWatchdog = window.setTimeout(
                    () => this.reportContactLost(this.refreshTimeoutBadge, this.refreshTimeoutMessage, this.refreshLastFailure),
                    this.refreshTimeoutMs + 1000,
                );
            },
            {{-- The post-run reload refreshes the status table to match the code on
                 disk. But the run's final phase just restarted the FrankenPHP workers,
                 and they may still be settling — a blind window.location.reload() would
                 hit a 500 and show the browser's error page. Probe the exempt progress
                 route first; only reload once a worker is actually serving responses.
                 Give up after the deadline and say so: a run that finishes but never
                 gets confirmed is a failure the operator needs to see, not something
                 to paper over with a blind reload into a server that may be down. --}}
            async reloadWhenHealthy() {
                if (this._destroyed || this.contactLost) {
                    return;
                }

                const remaining = this.refreshDeadline - Date.now();

                if (remaining <= 0) {
                    this.reportContactLost(this.refreshTimeoutBadge, this.refreshTimeoutMessage, this.refreshLastFailure);

                    return;
                }

                try {
                    const response = await fetch(this.progressUrl, {
                        headers: { 'Accept': 'application/json' },
                        signal: this.abortAfter(Math.min(3000, remaining)),
                    });

                    if (this._destroyed || this.contactLost) {
                        return;
                    }

                    if (response.ok) {
                        this.clearRefreshTimers();
                        window.location.reload();

                        return;
                    }

                    this.refreshLastFailure = `the server answered with HTTP ${response.status}`;
                } catch (error) {
                    {{-- Workers still restarting — keep waiting. --}}
                    this.refreshLastFailure = this.describeFetchFailure(error);
                }

                if (this._destroyed || this.contactLost) {
                    return;
                }

                if (Date.now() >= this.refreshDeadline) {
                    this.reportContactLost(this.refreshTimeoutBadge, this.refreshTimeoutMessage, this.refreshLastFailure);

                    return;
                }

                this.refreshTimer = window.setTimeout(() => this.reloadWhenHealthy(), 1500);
            },
            {{-- Without an abort the probe can sit on a pending promise forever:
                 Caddy stays up and holds the connection open while FrankenPHP
                 respawns its workers, so the request neither fails nor completes. --}}
            abortAfter(ms) {
                if (typeof AbortSignal === 'undefined' || typeof AbortSignal.timeout !== 'function') {
                    return undefined;
                }

                return AbortSignal.timeout(ms);
            },
            describeFetchFailure(error) {
                if (error?.name === 'TimeoutError' || error?.name === 'AbortError') {
                    return 'the request timed out with no response';
                }

                return error?.message || 'the request failed';
            },
            reportContactLost(badge, message, detail) {
                if (this._destroyed || this.contactLost) {
                    return;
                }

                this.clearRefreshTimers();
                window.clearTimeout(this._pollTimer);
                this._pollTimer = null;
                this.running = false;
                this.refreshing = false;
                this.contactLost = true;
                this.contactLostBadge = badge;
                this.contactLostMessage = message;
                this.contactLostDetail = detail;

                {{-- The stashed payload only makes sense for a reload we actually
                     perform; leaving it behind would make some later navigation
                     pop a stale "Run complete" banner. --}}
                this.forgetAfterRefresh();

                console.error(`[deployment] ${message} (${detail})`);
            },
            clearContactLost() {
                this.contactLost = false;
                this.contactLostBadge = '';
                this.contactLostMessage = '';
                this.contactLostDetail = '';
            },
            clearRefreshTimers() {
                window.clearTimeout(this.refreshTimer);
                window.clearTimeout(this.refreshWatchdog);
                this.refreshTimer = null;
                this.refreshWatchdog = null;
            },
            {{-- The operator's explicit call after a stall: re-stash the run outcome
                 so the reloaded page still reports how the run ended. --}}
            reloadNow() {
                this.clearRefreshTimers();
                this.rememberAfterRefresh();
                window.location.reload();
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
        @follow-update-progress.window="followDetachedRun()"
        @deployment-run-recorded="finishRun($event.detail || {})"
        @keydown.escape.window="closeRunLog()"
    >
        <x-ui.page-header
            :title="__('Updates')"
            :subtitle="__('Launch a durable background update per software source. The detached process survives web-worker restarts, runs migrations under maintenance mode, reloads the runtime, and records its progress here.')"
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

        {{-- Two banners, because one lead-in cannot be right for both causes. The
             single message this replaced opened with "public repositories do not
             need a token" even when git had just asked for a username. --}}
        @if ($credentialFailures !== [])
            <x-ui.alert variant="warning">
                {{ __('These software sources need credentials: :sources. Git asked for a username or was refused. Add the owner token in', ['sources' => implode(', ', $credentialFailures)]) }}
                <a href="{{ route('admin.system.software.github-access.index') }}" class="font-medium underline" wire:navigate>{{ __('GitHub Access') }}</a>.
            </x-ui.alert>
        @endif

        @if ($checkFailures !== [])
            <x-ui.alert variant="warning">
                {{ __('Could not check latest commits for these software sources: :sources. Public GitHub repositories do not need a token; see the Remote column for the Git response.', ['sources' => implode(', ', $checkFailures)]) }}
            </x-ui.alert>
        @endif

        @if ($hasUnpushedSources)
            <x-ui.alert variant="danger">
                <p class="font-medium">{{ __('Software updates are blocked by local-only commits.') }}</p>
                <p class="mt-1 text-sm">{{ __('These sources have commits that are not on their configured remotes: :sources. Push or reconcile them outside Belimbing, then refresh this status. Starting an update cannot fast-forward these checkouts and would otherwise fail after maintenance begins.', ['sources' => implode(', ', $unpushedSourceLabels)]) }}</p>
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
                            <h2 class="text-base font-medium text-ink">{{ __('Software sources') }}</h2>
                            <x-ui.help @click="helpOpen = ! helpOpen" ::aria-expanded="helpOpen" />
                        </div>
                        <p class="mt-1 text-sm text-muted">{{ __('Update launches a detached process that pulls the selected sources, installs changed PHP dependencies (or refreshes the autoloader), builds frontend assets, runs migrations, and reloads workers. Private repositories use the token set in') }}
                            <a href="{{ route('admin.system.software.github-access.index') }}" class="font-medium underline" wire:navigate>{{ __('GitHub Access') }}</a>.</p>
                        <p class="mt-2 text-xs text-muted">
                            {{-- Say how old this table is. Without it a page left open looks
                                 exactly like a page just loaded, and the operator launches a
                                 deployment against a picture of the world from hours ago. --}}
                            {{ __('Status collected') }} <x-ui.relative-time :value="$statusCollectedAt" />
                            <button
                                type="button"
                                wire:click="refreshStatus"
                                wire:loading.attr="disabled"
                                x-bind:disabled="running || refreshing || updateInProgress"
                                class="ml-1 font-medium underline hover:text-ink"
                            >
                                <span wire:loading.remove wire:target="refreshStatus">{{ __('Refresh') }}</span>
                                <span wire:loading wire:target="refreshStatus">{{ __('Refreshing…') }}</span>
                            </button>
                        </p>
                    </div>
                    <div class="ml-auto flex shrink-0 flex-wrap justify-end gap-2">
                        <x-ui.button type="button" variant="primary" wire:click="updateAll" x-on:click="openRunLog(); followDetachedRun()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing || updateInProgress || maintenanceActive || $wire.hasUnpushedSources || ! $wire.behind">
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
                        <p>{{ __('A software source is the repository that delivers the platform, a Domain, a module slot, or an Extension. Domains and Extensions remain the operator-facing lifecycle units; source details explain where updates come from.') }}</p>
                    </div>
                </div>
            </div>

            <x-ui.table container="flush" :caption="__('Deployment software sources')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Software source') }}</x-ui.th>
                        <x-ui.th>{{ __('Branch') }}</x-ui.th>
                        <x-ui.th>{{ __('Local') }}</x-ui.th>
                        <x-ui.th>{{ __('Remote') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Status') }}</x-ui.th>
                    </tr>
                </x-slot>

                @foreach ($status as $s)
                    <tr wire:key="dist-{{ $s['key'] }}">
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            <div class="text-sm font-medium text-ink">{{ $s['label'] }}</div>
                            <div class="font-mono text-xs text-muted">{{ $s['repo'] ?? $s['path'] }}</div>
                            @if ($s['working_tree']['dirty'] > 0 || $s['working_tree']['ahead'] > 0)
                                {{-- Uncommitted/unpushed changes inside a source's own nested repo never show in the platform repo's git status; surface them here so a tool that wrote into the source (e.g. schema incubation) can't leave the operator in the dark. --}}
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    @if ($s['working_tree']['dirty'] > 0)
                                        <x-ui.badge variant="warning" :title="__('Uncommitted changes in this source — commit them in its repository.')">{{ trans_choice('{1} :count uncommitted change|[2,*] :count uncommitted changes', (int) $s['working_tree']['dirty'], ['count' => $s['working_tree']['dirty']]) }}</x-ui.badge>
                                    @endif
                                    @if ($s['working_tree']['ahead'] > 0)
                                        <x-ui.badge variant="warning" :title="__('Local commits not yet pushed to this source\'s remote.')">{{ trans_choice('{1} :count unpushed commit|[2,*] :count unpushed commits', (int) $s['working_tree']['ahead'], ['count' => $s['working_tree']['ahead']]) }}</x-ui.badge>
                                    @endif
                                </div>
                            @endif
                            @if ($s['upstream'] ?? null)
                                <div class="mt-1.5 text-xs text-muted">
                                    <span class="font-medium">{{ __('Upstream') }}</span>
                                    <span class="font-mono">{{ ($s['upstream']['repo'] ?? $s['upstream']['remote']).'@'.($s['upstream']['branch'] ?? '—') }}</span>
                                    @if ($s['upstream']['head'])
                                        <span class="font-mono">{{ $s['upstream']['head']['short'] }}</span>
                                    @endif
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    @if ($s['upstream']['relationship'] === 'contained')
                                        <x-ui.badge variant="success" :title="__('Every upstream commit is already in the deployment fork.')">{{ __('Upstream contained') }}</x-ui.badge>
                                    @elseif ($s['upstream']['relationship'] === 'fast_forwardable')
                                        <x-ui.badge variant="warning" :title="__('The upstream has commits this fork does not; the fork has none of its own, so it can fast-forward.')">{{ trans_choice('{1} Upstream ahead by :count commit — fast-forwardable|[2,*] Upstream ahead by :count commits — fast-forwardable', (int) $s['upstream']['behind'], ['count' => $s['upstream']['behind']]) }}</x-ui.badge>
                                    @elseif ($s['upstream']['relationship'] === 'divergent')
                                        <x-ui.badge variant="warning" :title="__('Both the fork and the upstream have commits the other lacks; reconciling them is a manual decision.')">{{ __('Diverged — fork +:ahead / upstream +:behind', ['ahead' => $s['upstream']['ahead'], 'behind' => $s['upstream']['behind']]) }}</x-ui.badge>
                                    @elseif ($s['upstream']['reason'])
                                        <span class="text-xs text-muted">{{ $s['upstream']['reason'] }}</span>
                                    @elseif ($s['upstream']['error'])
                                        <span class="text-xs text-muted">{{ $s['upstream']['error'] }}</span>
                                        @if ($s['upstream']['error_detail'])
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-xs text-muted underline">{{ __('Git response') }}</summary>
                                                <pre class="mt-1 max-w-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-[11px] text-muted">{{ $s['upstream']['error_detail'] }}</pre>
                                            </details>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-sm text-muted">{{ $s['branch'] ?? '—' }}</td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            @if ($s['current'])
                                <span class="font-mono text-sm text-ink">{{ $s['current']['short'] }}</span>
                                <div class="text-xs text-muted"><x-ui.relative-time :value="$s['current']['date']" /></div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            @if ($s['latest'])
                                <span class="font-mono text-sm text-ink">{{ $s['latest']['short'] }}</span>
                                <div class="text-xs text-muted">
                                    <x-ui.relative-time
                                        :value="$s['latest']['date']"
                                        :fallback-title="$s['latest']['date_error'] ?? null"
                                    />
                                </div>
                            @elseif ($s['error'] === null && ! $latestStatusLoaded && ! $maintenanceActive && ! $updateInProgress)
                                <span class="inline-flex items-center gap-1.5 text-xs text-muted">
                                    <x-icon name="heroicon-o-arrow-path" class="h-3.5 w-3.5 animate-spin" />
                                    {{ __('Checking…') }}
                                </span>
                            @elseif ($s['error'] === null && ! $latestStatusLoaded && ($maintenanceActive || $updateInProgress))
                                <span class="text-xs text-muted">—</span>
                            @else
                                {{-- One actionable line, with git's own words behind a disclosure.
                                     A three-line `fatal:` printed straight into this cell blew up
                                     the row height and buried the cause at the end of it. --}}
                                <span class="text-xs text-muted">{{ $s['error'] }}</span>
                                @if ($s['error_detail'] ?? null)
                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-xs text-muted underline">{{ __('Git response') }}</summary>
                                        <pre class="mt-1 max-w-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-[11px] text-muted">{{ $s['error_detail'] }}</pre>
                                    </details>
                                @endif
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-right">
                            @if ($s['error'] === null && ! $latestStatusLoaded && ! $maintenanceActive && ! $updateInProgress)
                                <x-ui.badge variant="info">{{ __('Checking') }}</x-ui.badge>
                            @elseif ($s['error'] === null && ! $latestStatusLoaded && ($maintenanceActive || $updateInProgress))
                                <span class="text-xs text-muted">—</span>
                            @elseif ($s['update_state'] === 'up_to_date' && (($s['upstream'] ?? null) === null || $s['upstream']['relationship'] === 'contained'))
                                <x-ui.badge variant="success">{{ __('Up to date') }}</x-ui.badge>
                            @elseif ($s['update_state'] === 'up_to_date')
                                {{-- Matching origin alone must not read as plainly current when a
                                     framework upstream exists and is not contained in the fork (#344). --}}
                                <x-ui.badge variant="warning" :title="__('The deployment fork matches its remote, but the framework upstream has commits this fork does not include yet.')">{{ __('Fork up to date') }}</x-ui.badge>
                            @elseif ($s['update_state'] === 'ahead')
                                <x-ui.badge variant="info" :title="__('Local HEAD already contains the remote branch head.')">{{ __('Ahead of remote') }}</x-ui.badge>
                            @elseif ($s['update_state'] === 'behind' && $s['working_tree']['ahead'] > 0)
                                <x-ui.badge variant="danger" :title="__('Push or reconcile this source\'s local commits before updating.')">{{ __('Update blocked') }}</x-ui.badge>
                            @elseif ($s['update_state'] === 'behind')
                                <x-ui.button type="button" size="sm" variant="primary" wire:click="updateRepo('{{ $s['key'] }}')" x-on:click="openRunLog(); followDetachedRun()" wire:loading.attr="disabled" x-bind:disabled="running || refreshing || updateInProgress || maintenanceActive" wire:target="updateRepo('{{ $s['key'] }}')">
                                    <span wire:loading.remove wire:target="updateRepo('{{ $s['key'] }}')">{{ __('Update') }}</span>
                                    <span wire:loading wire:target="updateRepo('{{ $s['key'] }}')">{{ __('Updating…') }}</span>
                                </x-ui.button>
                            @else
                                <x-ui.badge variant="warning" :title="$s['error'] ?? __('Status could not be determined.')">{{ __('Unknown') }}</x-ui.badge>
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
                    <p class="ml-auto max-w-sm text-right text-xs text-muted">
                        {{ __('Worker reloads are run by the host deployment tool. This page records their health and outcome.') }}
                    </p>
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
                                        <span x-show="! running && contactLost">{{ __('Run finished, page not confirmed') }}</span>
                                        <span x-show="! running && ! refreshing && ! contactLost && statusIs('pending')">{{ __('Run in progress') }}</span>
                                        <span x-show="! running && ! refreshing && ! contactLost && justRefreshed">{{ __('Run complete') }}</span>
                                        <span x-show="! running && ! refreshing && ! contactLost && ! justRefreshed && ! statusIs('pending')">{{ __('Last run') }}</span>
                                    </h2>

                                    <x-ui.badge variant="info" x-show="running" x-cloak>
                                        <x-icon name="heroicon-o-arrow-path" class="mr-1 h-3.5 w-3.5 animate-spin" />
                                        {{ __('Running') }}
                                    </x-ui.badge>
                                    {{-- The run's own outcome survives a failed refresh: the run
                                         succeeded even when confirming it did not. --}}
                                    <x-ui.badge variant="success" x-show="! running && (refreshing || contactLost) && statusIs('success')" x-cloak>
                                        {{ __('Complete') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="warning" x-show="! running && (refreshing || contactLost) && statusIs('warning')" x-cloak>
                                        {{ __('Warnings') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="danger" x-show="! running && (refreshing || contactLost) && statusIs('error')" x-cloak>
                                        {{ __('Needs action') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="warning" x-show="! running && ! contactLost && statusIs('pending')" x-cloak>
                                        {{ __('In progress') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="info" x-show="refreshing && ! running" x-cloak>
                                        <x-icon name="heroicon-o-arrow-path" class="mr-1 h-3.5 w-3.5 animate-spin" />
                                        {{ __('Refreshing table') }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="danger" x-show="contactLost && ! running" x-cloak>
                                        <x-icon name="heroicon-o-exclamation-triangle" class="mr-1 h-3.5 w-3.5" />
                                        <span x-text="contactLostBadge"></span>
                                    </x-ui.badge>
                                    @if ($runStatus !== 'idle' && $runStatus !== 'pending')
                                        <x-ui.badge :variant="$runVariant" x-show="! running && ! refreshing && ! contactLost">{{ $runLabel }}</x-ui.badge>
                                    @endif
                                </div>

                                <p class="mt-1 text-xs text-muted" x-show="running" x-cloak>{{ __('Streaming live output. You can dismiss this window; the run continues.') }}</p>
                                <p class="mt-1 text-xs text-muted" x-show="refreshing && ! running" x-cloak>{{ __('Run log saved. Reloading this page so commits and actions match the code on disk.') }}</p>
                                <p class="mt-1 text-xs text-muted" x-show="statusIs('pending') && ! running && ! refreshing && ! contactLost" x-cloak>{{ __('Background work is still running. BLB will refresh this page and record the final result when it finishes.') }}</p>
                                <p class="mt-1 text-xs text-muted" x-show="justRefreshed && ! running && ! refreshing" x-cloak>{{ __('Status refreshed. Current commits and actions now reflect the code on disk.') }}</p>

                                {{-- A stalled feed is reported, never swallowed: the operator is
                                     told what did not happen, what is stale because of it, and is
                                     handed the reload the page gave up on doing by itself. --}}
                                <div x-show="contactLost && ! running" x-cloak>
                                    <p class="mt-1 text-xs text-status-danger" x-text="contactLostMessage"></p>
                                    <p class="mt-1 font-mono text-[11px] text-muted" x-text="contactLostDetail"></p>
                                    <x-ui.button type="button" size="sm" variant="outline" class="mt-2" x-on:click="reloadNow()">
                                        {{ __('Reload the page') }}
                                    </x-ui.button>
                                </div>

                                @if ($runAt)
                                    <p class="mt-1 text-xs text-muted" x-show="! running && ! refreshing && ! contactLost">
                                        {{ __('Last run') }} <x-ui.datetime :value="$runAt" />@if ($runSummary !== '') · {{ $runSummary }}@endif
                                    </p>
                                @else
                                    <p class="mt-1 text-xs text-muted" x-show="! running && ! refreshing && ! contactLost">{{ __('No update has run yet.') }}</p>
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
