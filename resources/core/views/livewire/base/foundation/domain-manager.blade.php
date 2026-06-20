@php
    $domainLifecycleTargets = 'install,disable,enable,uninstall';
    $domainActionTargets = $domainLifecycleTargets.',openUninstall,cancelUninstall';
@endphp

<div
    class="space-y-6"
    x-data="{
        runLogOpen: @js(session()->has('command-log')),
        dismissed: false,
        lifecycleOpen: false,
        lifecycleTitle: @js(__('Run in progress')),
        lifecycleDescription: @js(__('BLB is recording the audit entry, updating domain discovery, reloading runtime, and refreshing this page. The run log will stay open when the refresh completes.')),
        startLifecycle(title, description) {
            this.lifecycleTitle = title;
            this.lifecycleDescription = description;
            this.lifecycleOpen = true;
            this.dismissed = false;
            this.runLogOpen = false;
        },
        confirmLifecycle(message, title, description, run) {
            if (! window.confirm(message)) {
                return;
            }

            this.startLifecycle(title, description);
            run();
        },
        runUninstall(phraseName, title, description) {
            const phrase = (this.$wire.uninstallPhrase || '').trim();

            if (phrase !== `uninstall ${phraseName}` && phrase !== `uninstall ${phraseName} and drop all tables`) {
                this.$wire.uninstall();

                return;
            }

            this.startLifecycle(title, description);
            this.$wire.uninstall();
        },
        closeRunLog() {
            this.dismissed = true;
            this.runLogOpen = false;
        },
    }"
    @keydown.escape.window="closeRunLog()"
>
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-ink">{{ __('Business Domains') }}</h1>
        <p class="text-sm text-muted">{{ __('A fresh Belimbing install ships Base and Core only. Install the business domains you need; disable or uninstall them here later.') }}</p>
    </header>

    <x-ui.session-flash />
    <div x-show="lifecycleOpen" x-cloak style="display: none;" class="fixed inset-0 z-40 bg-black/50"></div>

    <div x-show="lifecycleOpen" x-cloak style="display: none;" class="pointer-events-none fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center">
        <div class="pointer-events-auto w-full max-w-2xl shadow-2xl">
            <x-ui.card>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-medium text-ink"><span x-text="lifecycleTitle">{{ __('Run in progress') }}</span></h2>
                            <x-ui.badge variant="info">{{ __('Running') }}</x-ui.badge>
                        </div>
                        <p class="mt-1 text-xs text-muted"><span x-text="lifecycleDescription">{{ __('BLB is recording the audit entry, updating domain discovery, reloading runtime, and refreshing this page. The run log will stay open when the refresh completes.') }}</span></p>
                    </div>
                </div>

                <div class="mt-2 h-72 overflow-y-auto rounded-md bg-surface-subtle px-3 py-2 font-mono text-[11px] leading-5 text-ink" aria-live="polite">
                    <div class="flex items-center gap-2 text-muted">
                        <x-icon name="heroicon-o-arrow-path" class="h-4 w-4 animate-spin" />
                        <span>{{ __('Working. The browser may show a refresh spinner near the end; that is expected.') }}</span>
                    </div>
                    <ol class="mt-3 list-decimal space-y-1 pl-4 text-muted">
                        <li>{{ __('Record who started the action and when.') }}</li>
                        <li>{{ __('Apply the domain state change.') }}</li>
                        <li>{{ __('Reload domain runtime discovery.') }}</li>
                        <li>{{ __('Refresh this page and show the run log.') }}</li>
                    </ol>
                    <div class="mt-3 text-muted">{{ __('Keep this tab open until the run log appears.') }}</div>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if (session('command-log'))
        <div x-show="runLogOpen && ! dismissed" x-transition.opacity class="fixed inset-0 z-40 bg-black/50" @click="closeRunLog()"></div>

        <div x-show="runLogOpen && ! dismissed" class="pointer-events-none fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center">
            <div class="pointer-events-auto w-full max-w-2xl shadow-2xl">
                <x-ui.card>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-medium text-ink">{{ __('Run log') }}</h2>
                            <p class="mt-1 text-xs text-muted">{{ __('The page refreshed after the domain runtime reload. This is the recorded output from the completed action.') }}</p>
                        </div>

                        <button
                            type="button"
                            x-on:click="closeRunLog()"
                            class="rounded-md text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent"
                            aria-label="{{ __('Dismiss run log') }}"
                        >
                            <x-icon name="heroicon-o-x-mark" class="h-5 w-5" />
                        </button>
                    </div>

                    <pre
                        x-ref="domainRunLog"
                        x-init="$nextTick(() => { $refs.domainRunLog.scrollTop = $refs.domainRunLog.scrollHeight })"
                        class="mt-2 h-72 overflow-y-auto rounded-md bg-surface-subtle px-3 py-2 font-mono text-[11px] leading-5 text-ink"
                    >{{ session('command-log') }}</pre>
                </x-ui.card>
            </div>
        </div>
    @endif

    @if (count($available) > 0)
        <section class="space-y-2">
            <h2 class="text-lg font-semibold text-ink">{{ __('Available business domains') }}</h2>
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($available as $name => $entry)
                    @php
                        $installConfirm = __('Install :name? This clones the repository and runs database migrations. After you confirm, BLB records who ran this, reloads domain runtime, refreshes this page, and then shows the run log.', ['name' => $name]);
                        $installTitle = __('Installing :name', ['name' => $name]);
                        $installDescription = __('BLB is cloning the domain, running migrations, recording the audit entry, reloading runtime, and refreshing this page. The run log will stay open when the refresh completes.');
                    @endphp
                    <x-ui.card wire:key="available-{{ $name }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="font-medium text-ink">{{ $name }}</div>
                            <x-ui.badge variant="info">{{ __('not installed') }}</x-ui.badge>
                        </div>
                        <p class="mt-1 text-sm text-muted">{{ $entry['description'] }}</p>
                        <p class="mt-2 text-xs text-muted"><code class="select-all">{{ $entry['repo'] }}</code></p>
                        @if ($canManage)
                            <x-ui.button
                                size="sm"
                                class="mt-3"
                                x-on:click="confirmLifecycle(
                                    {{ \Illuminate\Support\Js::from($installConfirm) }},
                                    {{ \Illuminate\Support\Js::from($installTitle) }},
                                    {{ \Illuminate\Support\Js::from($installDescription) }},
                                    () => $wire.install({{ \Illuminate\Support\Js::from($name) }})
                                )"
                                wire:loading.attr="disabled"
                                wire:target="{{ $domainActionTargets }}"
                                x-bind:disabled="lifecycleOpen"
                            >
                                <span wire:loading.remove wire:target="install('{{ $name }}')">{{ __('Install') }}</span>
                                <span wire:loading wire:target="install('{{ $name }}')">{{ __('Installing…') }}</span>
                            </x-ui.button>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>
        </section>
    @endif

    <section class="space-y-2">
        <h2 class="text-lg font-semibold text-ink">{{ __('Installed business domains') }}</h2>
        <div class="grid gap-4 md:grid-cols-3">
            @forelse ($installed as $domain)
                <x-ui.card wire:key="domain-{{ $domain['name'] }}">
                    @php
                        $installation = $domain['installation'];
                        $installerName = $installation['actor_name'] ?? null;
                        $installerEmail = $installation['actor_email'] ?? null;
                        $installerLabel = $installerName ?: ($installerEmail ?: ($installation ? $installation['actor_type'].'#'.$installation['actor_id'] : null));
                        $domainName = $domain['name'];
                        $enableConfirm = __('Enable :name? After you confirm, BLB records who ran this, reloads domain runtime, refreshes this page, and then shows the run log.', ['name' => $domainName]);
                        $enableTitle = __('Enabling :name', ['name' => $domainName]);
                        $enableDescription = __('BLB is recording the audit entry, re-enabling discovery, reloading runtime, and refreshing this page. The run log will stay open when the refresh completes.');
                        $disableConfirm = __('Disable :name? Its pages, menus, and settings disappear until re-enabled; code and data are untouched. After you confirm, BLB records who ran this, reloads domain runtime, refreshes this page, and then shows the run log.', ['name' => $domainName]);
                        $disableTitle = __('Disabling :name', ['name' => $domainName]);
                        $disableDescription = __('BLB is recording the audit entry, disabling discovery, reloading runtime, and refreshing this page. The run log will stay open when the refresh completes.');
                    @endphp
                    <div class="flex items-start justify-between gap-2">
                        <div class="font-medium text-ink">{{ $domain['name'] }}</div>
                        <div class="flex flex-wrap justify-end gap-1">
                            @if ($domain['disabled'])
                                <x-ui.badge variant="warning">{{ __('disabled') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="success">{{ __('active') }}</x-ui.badge>
                            @endif
                            @unless ($domain['git']['hasGit'])
                                <x-ui.badge variant="warning" tooltip="{{ __('No .git directory: this checkout cannot pull updates.') }}">{{ __('no git') }}</x-ui.badge>
                            @endunless
                        </div>
                    </div>
                    <div class="mt-1 text-sm text-muted">{{ __(':n module(s):', ['n' => count($domain['modules'])]) }} {{ implode(', ', $domain['modules']) }}</div>
                    @if ($installation)
                        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-2 gap-y-1 text-xs text-muted">
                            <dt class="font-medium text-ink">{{ __('Installed') }}</dt>
                            <dd><x-ui.datetime :value="$installation['occurred_at']" /></dd>
                            <dt class="font-medium text-ink">{{ __('By') }}</dt>
                            <dd>
                                <span>{{ $installerLabel }}</span>
                                @if ($installerName && $installerEmail)
                                    <span class="text-muted">({{ $installerEmail }})</span>
                                @endif
                            </dd>
                        </dl>
                    @endif

                    @if ($domain['git']['dirty'] || $domain['git']['unpushed'] > 0)
                        <div class="mt-2 text-xs text-status-warning">
                            @if ($domain['git']['dirty'])
                                {{ __('Has uncommitted changes.') }}
                            @endif
                            @if ($domain['git']['unpushed'] > 0)
                                {{ __(':n unpushed commit(s).', ['n' => $domain['git']['unpushed']]) }}
                            @endif
                        </div>
                    @endif

                    @if ($canManage)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($domain['disabled'])
                                <x-ui.button
                                    size="sm"
                                    variant="secondary"
                                    x-on:click="confirmLifecycle(
                                        {{ \Illuminate\Support\Js::from($enableConfirm) }},
                                        {{ \Illuminate\Support\Js::from($enableTitle) }},
                                        {{ \Illuminate\Support\Js::from($enableDescription) }},
                                        () => $wire.enable({{ \Illuminate\Support\Js::from($domainName) }})
                                    )"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $domainActionTargets }}"
                                    x-bind:disabled="lifecycleOpen"
                                >
                                    <span wire:loading.remove wire:target="enable('{{ $domain['name'] }}')">{{ __('Enable') }}</span>
                                    <span wire:loading wire:target="enable('{{ $domain['name'] }}')">{{ __('Enabling…') }}</span>
                                </x-ui.button>
                            @else
                                <x-ui.button
                                    size="sm"
                                    variant="secondary"
                                    x-on:click="confirmLifecycle(
                                        {{ \Illuminate\Support\Js::from($disableConfirm) }},
                                        {{ \Illuminate\Support\Js::from($disableTitle) }},
                                        {{ \Illuminate\Support\Js::from($disableDescription) }},
                                        () => $wire.disable({{ \Illuminate\Support\Js::from($domainName) }})
                                    )"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $domainActionTargets }}"
                                    x-bind:disabled="lifecycleOpen"
                                >
                                    <span wire:loading.remove wire:target="disable('{{ $domain['name'] }}')">{{ __('Disable') }}</span>
                                    <span wire:loading wire:target="disable('{{ $domain['name'] }}')">{{ __('Disabling…') }}</span>
                                </x-ui.button>
                            @endif
                            <x-ui.button
                                size="sm"
                                variant="danger"
                                wire:click="openUninstall('{{ $domain['name'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="{{ $domainActionTargets }}"
                                x-bind:disabled="lifecycleOpen"
                            >
                                <span wire:loading.remove wire:target="openUninstall('{{ $domain['name'] }}')">{{ __('Uninstall') }}</span>
                                <span wire:loading wire:target="openUninstall('{{ $domain['name'] }}')">{{ __('Opening…') }}</span>
                            </x-ui.button>
                        </div>
                    @endif

                    @if ($uninstallTarget === $domain['name'])
                        @php
                            $phraseName = strtolower($domain['name']);
                            $uninstallTitle = __('Uninstalling :name', ['name' => $domain['name']]);
                            $uninstallDescription = __('BLB is recording the audit entry, removing the domain checkout, reloading runtime, and refreshing this page. The run log will stay open when the refresh completes.');
                        @endphp
                        <div class="mt-3 space-y-2 rounded-2xl border border-status-danger-border bg-status-danger-subtle p-3">
                            <p class="text-sm font-medium text-status-danger">{{ __('Uninstall :name?', ['name' => $domain['name']]) }}</p>
                            @if ($domain['git']['dirty'] || $domain['git']['unpushed'] > 0)
                                <p class="text-xs text-status-danger">{{ __('Warning: this checkout has uncommitted or unpushed work that will be lost forever.') }}</p>
                            @endif
                            <p class="text-xs text-status-danger">
                                {{ __('Type :phrase to delete the code but keep all database tables (reinstalling adopts them again), or :dropPhrase to also drop its tables, migration entries, and settings.', [
                                    'phrase' => '"uninstall '.$phraseName.'"',
                                    'dropPhrase' => '"uninstall '.$phraseName.' and drop all tables"',
                                ]) }}
                            </p>
                            <p class="text-xs text-status-danger">
                                {{ __('After you click Uninstall, BLB records who ran this, reloads domain runtime, refreshes this page, and then shows the run log.') }}
                            </p>
                            <x-ui.acknowledge-input
                                :phrase="'uninstall '.$phraseName"
                                wire:model="uninstallPhrase"
                                x-on:keydown.enter.prevent="runUninstall(
                                    {{ \Illuminate\Support\Js::from($phraseName) }},
                                    {{ \Illuminate\Support\Js::from($uninstallTitle) }},
                                    {{ \Illuminate\Support\Js::from($uninstallDescription) }}
                                )"
                            />
                            <div class="flex gap-2">
                                <x-ui.button
                                    size="sm"
                                    variant="danger"
                                    x-on:click="runUninstall(
                                        {{ \Illuminate\Support\Js::from($phraseName) }},
                                        {{ \Illuminate\Support\Js::from($uninstallTitle) }},
                                        {{ \Illuminate\Support\Js::from($uninstallDescription) }}
                                    )"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $domainActionTargets }}"
                                    x-bind:disabled="lifecycleOpen"
                                >
                                    <span wire:loading.remove wire:target="uninstall">{{ __('Uninstall') }}</span>
                                    <span wire:loading wire:target="uninstall">{{ __('Uninstalling…') }}</span>
                                </x-ui.button>
                                <x-ui.button
                                    size="sm"
                                    variant="secondary"
                                    wire:click="cancelUninstall"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $domainActionTargets }}"
                                    x-bind:disabled="lifecycleOpen"
                                >
                                    <span wire:loading.remove wire:target="cancelUninstall">{{ __('Cancel') }}</span>
                                    <span wire:loading wire:target="cancelUninstall">{{ __('Cancelling…') }}</span>
                                </x-ui.button>
                            </div>
                        </div>
                    @endif
                </x-ui.card>
            @empty
                <x-ui.card>
                    <div class="text-sm text-muted">{{ __('No non-Core business domains are installed.') }}</div>
                </x-ui.card>
            @endforelse
        </div>
    </section>

    <p class="text-xs text-muted">
        {{ __('Database state kept by an uninstall — and any other unclaimed tables or settings — is listed under') }}
        <a href="{{ route('admin.system.database-residue.index') }}" class="text-accent hover:underline" wire:navigate>{{ __('Database Residue') }}</a>.
    </p>
</div>
