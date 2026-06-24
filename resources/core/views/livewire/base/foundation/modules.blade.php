@php
    $lifecycleTargets = 'install,disable,enable,uninstall';
    $actionTargets = $lifecycleTargets.',installExtension,openUninstall,cancelUninstall,refreshCatalog';
@endphp

<div
    class="space-y-6"
    x-data="{
        runLogOpen: @js(session()->has('command-log')),
        dismissed: false,
        lifecycleOpen: false,
        lifecycleTitle: @js(__('Run in progress')),
        lifecycleDescription: @js(__('BLB is recording the audit entry, updating discovery, reloading runtime, and refreshing this page. The run log will stay open when the refresh completes.')),
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
    <x-ui.page-header
        :title="__('Modules')"
        :subtitle="__('Manage add-in software and install more from BelimbingApp. Domain and extension rows are bundles you install or remove; expand a bundle to see its modules. The built-in Platform Baseline ships with Base and Core and is always installed.')"
    >
        <x-slot:help>
            <dl class="space-y-3">
                <div>
                    <dt class="font-medium text-ink">{{ __('Platform Baseline') }}</dt>
                    <dd>{{ __('The built-in BLB platform: Base infrastructure plus mandatory Core modules. It ships with the main repo and cannot be installed, disabled, or removed here.') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-ink">{{ __('Bundle') }}</dt>
                    <dd>{{ __('A delivered unit of software. Add-in bundles are business domains (such as People) or extensions that operators install or remove; the Platform Baseline is read-only here.') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-ink">{{ __('Module') }}</dt>
                    <dd>{{ __('An ownership boundary inside delivered software (such as People → Payroll). Core uses the same module shape for mandatory platform modules; modules are not installed or removed on their own.') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-ink">{{ __('Contribution') }}</dt>
                    <dd>{{ __('Runtime behavior one bundle adds to another module’s extension seam — for example a Malaysia payroll pack contributing to Payroll. A contribution rides along with its bundle.') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-ink">{{ __('Slot') }}</dt>
                    <dd>{{ __('A whole-module implementation chosen once per deployment. Switching a slot is a data migration, never a toggle on this page.') }}</dd>
                </div>
            </dl>
        </x-slot:help>
    </x-ui.page-header>

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
                        <p class="mt-1 text-xs text-muted"><span x-text="lifecycleDescription"></span></p>
                    </div>
                </div>
                <div class="mt-2 h-72 overflow-y-auto rounded-md bg-surface-subtle px-3 py-2 font-mono text-[11px] leading-5 text-ink" aria-live="polite">
                    <div class="flex items-center gap-2 text-muted">
                        <x-icon name="heroicon-o-arrow-path" class="h-4 w-4 animate-spin" />
                        <span>{{ __('Working. The browser may show a refresh spinner near the end; that is expected.') }}</span>
                    </div>
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
                            <p class="mt-1 text-xs text-muted">{{ __('The page refreshed after the runtime reload. This is the recorded output from the completed action.') }}</p>
                        </div>
                        <button type="button" x-on:click="closeRunLog()" class="rounded-md text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent" aria-label="{{ __('Dismiss run log') }}">
                            <x-icon name="heroicon-o-x-mark" class="h-5 w-5" />
                        </button>
                    </div>
                    <pre x-ref="runLog" x-init="$nextTick(() => { $refs.runLog.scrollTop = $refs.runLog.scrollHeight })" class="mt-2 h-72 overflow-y-auto rounded-md bg-surface-subtle px-3 py-2 font-mono text-[11px] leading-5 text-ink">{{ session('command-log') }}</pre>
                </x-ui.card>
            </div>
        </div>
    @endif

    @php $availableCount = count($available) + count($availableExtensions) + count($catalogEntries); @endphp

    <x-ui.tabs
        :tabs="[
            ['id' => 'installed', 'label' => __('Installed')],
            ['id' => 'available', 'label' => __('Available (:n)', ['n' => $availableCount])],
        ]"
        :default="$tab"
        persistence="query"
        query-key="tab"
        wire-action="setTab"
    >
        <x-ui.tab id="installed"><div class="space-y-6">
        @if (count($dependencyIssues) > 0)
            <div class="rounded-2xl border border-danger-border bg-danger-surface px-4 py-3 text-sm text-danger-ink">
                <div class="font-medium">{{ __('Module dependency issues') }}</div>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($dependencyIssues as $row)
                        @if ($row['issue'] === 'missing')
                            <li>{{ __(':a requires :b (:constraint), but it is not installed or enabled.', ['a' => $row['requiring'], 'b' => $row['required'], 'constraint' => $row['constraint']]) }}</li>
                        @else
                            <li>{{ __(':a requires :b (:constraint), but the installed version is :version.', ['a' => $row['requiring'], 'b' => $row['required'], 'constraint' => $row['constraint'], 'version' => $row['installed_version'] ?: __('unversioned')]) }}</li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @else
            <div class="rounded-2xl border border-success-border bg-success-surface px-4 py-3 text-sm text-success-ink">
                {{ __('All required module dependencies are satisfied.') }}
            </div>
        @endif

        <x-ui.card x-data="{ open: false }">
            <div class="flex items-start justify-between gap-2">
                <div class="font-medium text-ink">{{ __('Built-in Platform') }}</div>
                <x-ui.badge variant="info">{{ __('baseline') }}</x-ui.badge>
            </div>
            <p class="mt-1 text-sm text-muted">{{ __('The Platform Baseline: Base infrastructure plus mandatory Core modules. Always installed; cannot be disabled or removed.') }}</p>
            @if ($platformBundle)
                @if ($platformBundle->repo)
                    <p class="mt-2 font-mono text-xs text-muted">{{ $platformBundle->repo }} · {{ $platformBundle->branch }}@if (! empty($platformBundle->commit['short'])) · {{ $platformBundle->commit['short'] }}@endif</p>
                @endif
                @if ($platformBundle->moduleCount() > 0)
                    <button type="button" x-on:click="open = ! open" class="mt-2 flex items-center gap-1 text-sm text-muted hover:text-ink">
                        <x-icon name="heroicon-o-chevron-right" class="h-4 w-4 transition-transform" x-bind:class="open && 'rotate-90'" />
                        {{ trans_choice('{1} :count module|[2,*] :count modules', $platformBundle->moduleCount(), ['count' => $platformBundle->moduleCount()]) }}
                    </button>
                    <div x-show="open" x-collapse class="mt-2 grid gap-2 md:grid-cols-2">
                        @foreach ($platformBundle->modules as $m)
                            <div class="rounded-lg border border-border-default px-3 py-2 text-xs">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-medium text-ink">{{ $m->label() }}</span>
                                    <span class="rounded-full border border-border-default px-2 py-0.5 text-muted">{{ $m->version ?: __('unversioned') }}</span>
                                </div>
                                <div class="mt-1 font-mono text-muted">{{ $m->path }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
                @include('livewire.base.foundation.partials.bundle-contributions', ['contributions' => $platformBundle->contributions])
            @endif
        </x-ui.card>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold text-ink">{{ __('Installed add-in business domains') }}</h2>
            <div class="grid gap-4 md:grid-cols-2">
                @forelse ($installed as $domain)
                    @php
                        $installation = $domain['installation'];
                        $installerName = $installation['actor_name'] ?? null;
                        $installerEmail = $installation['actor_email'] ?? null;
                        $installerLabel = $installerName ?: ($installerEmail ?: ($installation ? $installation['actor_type'].'#'.$installation['actor_id'] : null));
                        $domainName = $domain['name'];
                        $enableConfirm = __('Enable :name? After you confirm, BLB records who ran this, reloads runtime, refreshes this page, and then shows the run log.', ['name' => $domainName]);
                        $enableTitle = __('Enabling :name', ['name' => $domainName]);
                        $enableDescription = __('BLB is recording the audit entry, re-enabling discovery, reloading runtime, and refreshing this page.');
                        $disableConfirm = __('Disable :name? Its pages, menus, and settings disappear until re-enabled; code and data are untouched.', ['name' => $domainName]);
                        $disableTitle = __('Disabling :name', ['name' => $domainName]);
                        $disableDescription = __('BLB is recording the audit entry, disabling discovery, reloading runtime, and refreshing this page.');
                    @endphp
                    <x-ui.card wire:key="domain-{{ $domainName }}" x-data="{ open: false }">
                        <div class="flex items-start justify-between gap-2">
                            <div class="font-medium text-ink">{{ $domainName }}</div>
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

                        <button type="button" x-on:click="open = ! open" class="mt-1 flex items-center gap-1 text-sm text-muted hover:text-ink">
                            <x-icon name="heroicon-o-chevron-right" class="h-4 w-4 transition-transform" x-bind:class="open && 'rotate-90'" />
                            {{ trans_choice('{0} no modules|{1} :count module|[2,*] :count modules', count($domain['modules']), ['count' => count($domain['modules'])]) }}
                        </button>

                        <div x-show="open" x-collapse class="mt-2 space-y-2">
                            @forelse ($domain['manifests'] as $m)
                                <div class="rounded-lg border border-border-default px-3 py-2 text-xs">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-ink">{{ $m->module ?: $m->name }}</span>
                                        <span class="rounded-full border border-border-default px-2 py-0.5 text-muted">{{ $m->version ?: __('unversioned') }}</span>
                                    </div>
                                    @if ($m->description !== '')
                                        <p class="mt-1 text-muted">{{ $m->description }}</p>
                                    @endif
                                    @if (count($m->requiresModules) > 0)
                                        <div class="mt-1 text-muted"><span class="font-semibold uppercase tracking-wide">{{ __('Requires') }}:</span> <span class="font-mono">{{ implode(', ', array_keys($m->requiresModules)) }}</span></div>
                                    @endif
                                    @if (count($m->consumesEvents) > 0)
                                        <div class="mt-1 text-muted"><span class="font-semibold uppercase tracking-wide">{{ __('Consumes') }}:</span> <span class="font-mono">{{ implode(', ', array_map('class_basename', $m->consumesEvents)) }}</span></div>
                                    @endif
                                    <div class="mt-1 font-mono text-muted">{{ $m->path }}</div>
                                </div>
                            @empty
                                <div class="text-xs text-muted">{{ implode(', ', $domain['modules']) ?: __('No module manifests found for this domain.') }}</div>
                            @endforelse
                        </div>

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

                        @php $bundle = $bundlesByLifecycle[$domainName] ?? null; @endphp
                        @if ($bundle && $bundle->repo)
                            <p class="mt-2 font-mono text-xs text-muted">{{ $bundle->repo }} · {{ $bundle->branch }}@if (! empty($bundle->commit['short'])) · {{ $bundle->commit['short'] }}@endif</p>
                        @endif
                        @if ($bundle)
                            @include('livewire.base.foundation.partials.bundle-contributions', ['contributions' => $bundle->contributions])
                        @endif

                        @if ($domain['git']['dirty'] || $domain['git']['unpushed'] > 0)
                            <div class="mt-2 text-xs text-status-warning">
                                @if ($domain['git']['dirty']){{ __('Has uncommitted changes.') }}@endif
                                @if ($domain['git']['unpushed'] > 0) {{ __(':n unpushed commit(s).', ['n' => $domain['git']['unpushed']]) }}@endif
                            </div>
                        @endif

                        @if ($canManage)
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($domain['disabled'])
                                    <x-ui.button size="sm" variant="secondary"
                                        x-on:click="confirmLifecycle({{ \Illuminate\Support\Js::from($enableConfirm) }}, {{ \Illuminate\Support\Js::from($enableTitle) }}, {{ \Illuminate\Support\Js::from($enableDescription) }}, () => $wire.enable({{ \Illuminate\Support\Js::from($domainName) }}))"
                                        wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                        {{ __('Enable') }}
                                    </x-ui.button>
                                @else
                                    <x-ui.button size="sm" variant="secondary"
                                        x-on:click="confirmLifecycle({{ \Illuminate\Support\Js::from($disableConfirm) }}, {{ \Illuminate\Support\Js::from($disableTitle) }}, {{ \Illuminate\Support\Js::from($disableDescription) }}, () => $wire.disable({{ \Illuminate\Support\Js::from($domainName) }}))"
                                        wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                        {{ __('Disable') }}
                                    </x-ui.button>
                                @endif
                                <x-ui.button size="sm" variant="danger" wire:click="openUninstall('{{ $domainName }}', 'domain')" wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                    {{ __('Uninstall') }}
                                </x-ui.button>
                            </div>
                        @endif

                        @if ($uninstallTarget === $domainName && $uninstallKind === 'domain')
                            @php
                                $phraseName = strtolower($domainName);
                                $uninstallTitle = __('Uninstalling :name', ['name' => $domainName]);
                                $uninstallDescription = __('BLB is recording the audit entry, removing the domain checkout, reloading runtime, and refreshing this page.');
                            @endphp
                            <div class="mt-3 space-y-2 rounded-2xl border border-status-danger-border bg-status-danger-subtle p-3">
                                <p class="text-sm font-medium text-status-danger">{{ __('Uninstall :name?', ['name' => $domainName]) }}</p>
                                @if ($domain['git']['dirty'] || $domain['git']['unpushed'] > 0)
                                    <p class="text-xs text-status-danger">{{ __('Warning: this checkout has uncommitted or unpushed work that will be lost forever.') }}</p>
                                @endif
                                <p class="text-xs text-status-danger">
                                    {{ __('Type :phrase to delete the code but keep all database tables (reinstalling adopts them again), or :dropPhrase to also drop its tables, migration entries, and settings.', [
                                        'phrase' => '"uninstall '.$phraseName.'"',
                                        'dropPhrase' => '"uninstall '.$phraseName.' and drop all tables"',
                                    ]) }}
                                </p>
                                <x-ui.acknowledge-input
                                    :phrase="'uninstall '.$phraseName"
                                    wire:model="uninstallPhrase"
                                    x-on:keydown.enter.prevent="runUninstall({{ \Illuminate\Support\Js::from($phraseName) }}, {{ \Illuminate\Support\Js::from($uninstallTitle) }}, {{ \Illuminate\Support\Js::from($uninstallDescription) }})"
                                />
                                <div class="flex gap-2">
                                    <x-ui.button size="sm" variant="danger"
                                        x-on:click="runUninstall({{ \Illuminate\Support\Js::from($phraseName) }}, {{ \Illuminate\Support\Js::from($uninstallTitle) }}, {{ \Illuminate\Support\Js::from($uninstallDescription) }})"
                                        wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                        {{ __('Uninstall') }}
                                    </x-ui.button>
                                    <x-ui.button size="sm" variant="secondary" wire:click="cancelUninstall" wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                        {{ __('Cancel') }}
                                    </x-ui.button>
                                </div>
                            </div>
                        @endif
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <div class="text-sm text-muted">{{ __('No non-Core business domains are installed. Install one from the Available tab.') }}</div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        @if (count($extensions) > 0)
            <section class="space-y-2">
                <h2 class="text-lg font-semibold text-ink">{{ __('Installed extensions') }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($extensions as $extension)
                        @php $extName = $extension['name']; @endphp
                        <x-ui.card wire:key="extension-{{ $extName }}" x-data="{ open: false }">
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-medium text-ink">{{ $extName }}</div>
                                <div class="flex flex-wrap justify-end gap-1">
                                    <x-ui.badge variant="info">{{ __('extension') }}</x-ui.badge>
                                    @unless ($extension['git']['hasGit'])
                                        <x-ui.badge variant="warning" tooltip="{{ __('No .git directory: this checkout cannot pull updates.') }}">{{ __('no git') }}</x-ui.badge>
                                    @endunless
                                </div>
                            </div>

                            <button type="button" x-on:click="open = ! open" class="mt-1 flex items-center gap-1 text-sm text-muted hover:text-ink">
                                <x-icon name="heroicon-o-chevron-right" class="h-4 w-4 transition-transform" x-bind:class="open && 'rotate-90'" />
                                {{ trans_choice('{0} no modules|{1} :count module|[2,*] :count modules', count($extension['modules']), ['count' => count($extension['modules'])]) }}
                            </button>

                            <div x-show="open" x-collapse class="mt-2 space-y-2">
                                @forelse ($extension['manifests'] as $m)
                                    <div class="rounded-lg border border-border-default px-3 py-2 text-xs">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-medium text-ink">{{ $m->module ?: $m->name }}</span>
                                            <span class="rounded-full border border-border-default px-2 py-0.5 text-muted">{{ $m->version ?: __('unversioned') }}</span>
                                        </div>
                                        @if ($m->description !== '')
                                            <p class="mt-1 text-muted">{{ $m->description }}</p>
                                        @endif
                                        <div class="mt-1 font-mono text-muted">{{ $m->path }}</div>
                                    </div>
                                @empty
                                    <div class="text-xs text-muted">{{ implode(', ', $extension['modules']) ?: __('No module manifests found for this extension.') }}</div>
                                @endforelse
                            </div>

                            @php $bundle = $bundlesByLifecycle[$extName] ?? null; @endphp
                            @if ($bundle && $bundle->repo)
                                <p class="mt-2 font-mono text-xs text-muted">{{ $bundle->repo }} · {{ $bundle->branch }}@if (! empty($bundle->commit['short'])) · {{ $bundle->commit['short'] }}@endif</p>
                            @endif
                            @if ($bundle)
                                @include('livewire.base.foundation.partials.bundle-contributions', ['contributions' => $bundle->contributions])
                            @endif

                            @if ($extension['git']['dirty'] || $extension['git']['unpushed'] > 0)
                                <div class="mt-2 text-xs text-status-warning">
                                    @if ($extension['git']['dirty']){{ __('Has uncommitted changes.') }}@endif
                                    @if ($extension['git']['unpushed'] > 0) {{ __(':n unpushed commit(s).', ['n' => $extension['git']['unpushed']]) }}@endif
                                </div>
                            @endif

                            @if ($canManage)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <x-ui.button size="sm" variant="danger" wire:click="openUninstall('{{ $extName }}', 'extension')" wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                        {{ __('Uninstall') }}
                                    </x-ui.button>
                                </div>
                            @endif

                            @if ($uninstallTarget === $extName && $uninstallKind === 'extension')
                                @php
                                    $phraseName = strtolower($extName);
                                    $uninstallTitle = __('Uninstalling :name', ['name' => $extName]);
                                    $uninstallDescription = __('BLB is removing the extension checkout, reloading runtime, and refreshing this page.');
                                @endphp
                                <div class="mt-3 space-y-2 rounded-2xl border border-status-danger-border bg-status-danger-subtle p-3">
                                    <p class="text-sm font-medium text-status-danger">{{ __('Uninstall :name?', ['name' => $extName]) }}</p>
                                    @if ($extension['git']['dirty'] || $extension['git']['unpushed'] > 0)
                                        <p class="text-xs text-status-danger">{{ __('Warning: this checkout has uncommitted or unpushed work that will be lost forever.') }}</p>
                                    @endif
                                    <p class="text-xs text-status-danger">
                                        {{ __('Type :phrase to delete the code but keep all database tables, or :dropPhrase to also drop its tables, migration entries, and settings.', [
                                            'phrase' => '"uninstall '.$phraseName.'"',
                                            'dropPhrase' => '"uninstall '.$phraseName.' and drop all tables"',
                                        ]) }}
                                    </p>
                                    <x-ui.acknowledge-input
                                        :phrase="'uninstall '.$phraseName"
                                        wire:model="uninstallPhrase"
                                        x-on:keydown.enter.prevent="runUninstall({{ \Illuminate\Support\Js::from($phraseName) }}, {{ \Illuminate\Support\Js::from($uninstallTitle) }}, {{ \Illuminate\Support\Js::from($uninstallDescription) }})"
                                    />
                                    <div class="flex gap-2">
                                        <x-ui.button size="sm" variant="danger"
                                            x-on:click="runUninstall({{ \Illuminate\Support\Js::from($phraseName) }}, {{ \Illuminate\Support\Js::from($uninstallTitle) }}, {{ \Illuminate\Support\Js::from($uninstallDescription) }})"
                                            wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                            {{ __('Uninstall') }}
                                        </x-ui.button>
                                        <x-ui.button size="sm" variant="secondary" wire:click="cancelUninstall" wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                            {{ __('Cancel') }}
                                        </x-ui.button>
                                    </div>
                                </div>
                            @endif
                        </x-ui.card>
                    @endforeach
                </div>
            </section>
        @endif

        @if (count($slotBundles) > 0)
            <section class="space-y-2">
                <h2 class="text-lg font-semibold text-ink">{{ __('Slot implementations') }}</h2>
                <p class="text-sm text-muted">{{ __('Whole-module implementations shipped as their own nested repository. The selected implementation fills its module path for this deployment; switching one is a data migration, not a control here.') }}</p>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($slotBundles as $slot)
                        <x-ui.card wire:key="slot-{{ $slot->key }}" x-data="{ open: false }">
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-medium text-ink">{{ $slot->path }}</div>
                                <x-ui.badge variant="info">{{ __('slot') }}</x-ui.badge>
                            </div>
                            @if ($slot->repo)
                                <p class="mt-1 font-mono text-xs text-muted">{{ $slot->repo }} · {{ $slot->branch }}@if (! empty($slot->commit['short'])) · {{ $slot->commit['short'] }}@endif</p>
                            @endif
                            <button type="button" x-on:click="open = ! open" class="mt-2 flex items-center gap-1 text-sm text-muted hover:text-ink">
                                <x-icon name="heroicon-o-chevron-right" class="h-4 w-4 transition-transform" x-bind:class="open && 'rotate-90'" />
                                {{ trans_choice('{0} no modules|{1} :count module|[2,*] :count modules', $slot->moduleCount(), ['count' => $slot->moduleCount()]) }}
                            </button>
                            <div x-show="open" x-collapse class="mt-2 space-y-2">
                                @foreach ($slot->modules as $m)
                                    <div class="rounded-lg border border-border-default px-3 py-2 text-xs">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-medium text-ink">{{ $m->label() }}</span>
                                            <span class="rounded-full border border-border-default px-2 py-0.5 text-muted">{{ $m->version ?: __('unversioned') }}</span>
                                        </div>
                                        <div class="mt-1 font-mono text-muted">{{ $m->path }}</div>
                                    </div>
                                @endforeach
                            </div>
                            @include('livewire.base.foundation.partials.bundle-contributions', ['contributions' => $slot->contributions])
                        </x-ui.card>
                    @endforeach
                </div>
            </section>
        @endif

        <p class="text-xs text-muted">
            {{ __('Database state kept by an uninstall — and any other unclaimed tables or settings — is listed under') }}
            <a href="{{ route('admin.system.database-residue.index') }}" class="text-accent hover:underline" wire:navigate>{{ __('Database Residue') }}</a>.
        </p>
        </div></x-ui.tab>

        <x-ui.tab id="available"><div class="space-y-6">
        @if (count($available) > 0)
            <section class="space-y-2">
                <h2 class="text-lg font-semibold text-ink">{{ __('Available add-in business domains') }}</h2>
                <div class="grid gap-4 md:grid-cols-3">
                    @foreach ($available as $name => $entry)
                        @php
                            $installConfirm = __('Install :name? This clones the repository and runs database migrations.', ['name' => $name]);
                            $installTitle = __('Installing :name', ['name' => $name]);
                            $installDescription = __('BLB is cloning the domain, running migrations, recording the audit entry, reloading runtime, and refreshing this page.');
                        @endphp
                        <x-ui.card wire:key="available-{{ $name }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-medium text-ink">{{ $name }}</div>
                                <x-ui.badge variant="info">{{ __('not installed') }}</x-ui.badge>
                            </div>
                            <p class="mt-1 text-sm text-muted">{{ $entry['description'] }}</p>
                            <p class="mt-2 text-xs text-muted"><code class="select-all">{{ $entry['repo'] }}</code></p>
                            @if ($canManage)
                                <x-ui.button size="sm" class="mt-3"
                                    x-on:click="confirmLifecycle({{ \Illuminate\Support\Js::from($installConfirm) }}, {{ \Illuminate\Support\Js::from($installTitle) }}, {{ \Illuminate\Support\Js::from($installDescription) }}, () => $wire.install({{ \Illuminate\Support\Js::from($name) }}))"
                                    wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                    {{ __('Install') }}
                                </x-ui.button>
                            @endif
                        </x-ui.card>
                    @endforeach
                </div>
            </section>
        @endif

        @if (count($availableExtensions) > 0)
            <section class="space-y-2">
                <h2 class="text-lg font-semibold text-ink">{{ __('Available extensions') }}</h2>
                <div class="grid gap-4 md:grid-cols-3">
                    @foreach ($availableExtensions as $folder => $entry)
                        @php
                            $extInstallConfirm = __('Install extension :name? This clones the private repository using its stored GitHub token and runs migrations.', ['name' => $folder]);
                            $extInstallTitle = __('Installing :name', ['name' => $folder]);
                            $extInstallDescription = __('BLB is cloning the extension, running migrations, reloading runtime, and refreshing this page.');
                        @endphp
                        <x-ui.card wire:key="available-extension-{{ $folder }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-medium text-ink">{{ $folder }}</div>
                                @if ($entry['has_token'])
                                    <x-ui.badge variant="success">{{ __('token ready') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="warning" tooltip="{{ __('No GitHub token stored for this owner — save one under GitHub Access if the repo is private.') }}">{{ __('no token') }}</x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-muted">{{ $entry['description'] }}</p>
                            <p class="mt-2 text-xs text-muted"><code class="select-all">{{ $entry['repo'] }}</code></p>
                            @if ($canManage)
                                <x-ui.button size="sm" class="mt-3"
                                    x-on:click="confirmLifecycle({{ \Illuminate\Support\Js::from($extInstallConfirm) }}, {{ \Illuminate\Support\Js::from($extInstallTitle) }}, {{ \Illuminate\Support\Js::from($extInstallDescription) }}, () => $wire.installExtension({{ \Illuminate\Support\Js::from($folder) }}))"
                                    wire:loading.attr="disabled" wire:target="{{ $actionTargets }}" x-bind:disabled="lifecycleOpen">
                                    {{ __('Install') }}
                                </x-ui.button>
                            @endif
                        </x-ui.card>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-ink">{{ __('BelimbingApp catalog') }}</h2>
                <div class="flex items-center gap-3">
                    <div class="text-xs text-muted">
                        @if ($catalogLastFetchedAt !== null)
                            {{ __('Last fetched :ts', ['ts' => $catalogLastFetchedAt->format('Y-m-d H:i:s')]) }}
                        @else
                            {{ __('Never fetched.') }}
                        @endif
                    </div>
                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="refreshCatalog" :disabled="! $canManage">
                        {{ __('Refresh from GitHub') }}
                    </x-ui.button>
                </div>
            </div>

            @if (count($catalogEntries) === 0)
                <x-ui.card>
                    <p class="text-sm text-muted">{{ __('No catalog entries cached. Refresh from GitHub to discover BelimbingApp bundles.') }}</p>
                </x-ui.card>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($catalogEntries as $entry)
                        @php $isInstalled = in_array($entry->moduleIdentifier, $installedModuleIds, true); @endphp
                        <x-ui.card wire:key="catalog-{{ $entry->repoName }}">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="font-medium text-ink">{{ $entry->moduleIdentifier ?: $entry->composerName }}</div>
                                    <x-ui.link kind="external" href="{{ $entry->htmlUrl }}" class="font-mono text-xs">{{ $entry->repoName }}</x-ui.link>
                                </div>
                                @if ($isInstalled)
                                    <span class="rounded-full border border-success-border bg-success-surface px-2 py-0.5 text-xs text-success-ink">{{ __('Installed') }}</span>
                                @else
                                    <span class="rounded-full border border-border-default px-2 py-0.5 text-xs text-muted">{{ $entry->version ?: __('unversioned') }}</span>
                                @endif
                            </div>
                            @if ($entry->description !== '')
                                <p class="mt-2 text-sm text-muted">{{ $entry->description }}</p>
                            @endif
                            @if (! $isInstalled)
                                <div class="mt-3 text-xs">
                                    <div class="font-semibold uppercase tracking-wide text-muted">{{ __('Install command') }}</div>
                                    <pre class="mt-1 select-all rounded-lg border border-border-default bg-surface-subtle px-2 py-1 font-mono text-xs">{{ $entry->suggestedInstallCommand() }}</pre>
                                    <p class="mt-1 text-muted">{{ __('Copy + run from a shell. Then composer install + php artisan migrate.') }}</p>
                                </div>
                            @endif
                        </x-ui.card>
                    @endforeach
                </div>
            @endif
        </section>
        </div></x-ui.tab>
    </x-ui.tabs>
</div>
