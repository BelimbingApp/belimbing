<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-ink">{{ __('Business Domains') }}</h1>
        <p class="text-sm text-muted">{{ __('A fresh Belimbing install ships Base and Core only. Install the business domains you need; disable or uninstall them here later.') }}</p>
    </header>

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if (session('error'))
        <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
    @endif
    @if (session('command-log'))
        <div
            x-data="{ open: true }"
            x-show="open"
            x-cloak
            style="display: none;"
        >
            <div
                x-transition.opacity
                class="fixed inset-0 z-40 bg-black/50"
                @click="open = false"
            ></div>

            <div class="pointer-events-none fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center">
                <div class="pointer-events-auto w-full max-w-2xl shadow-2xl">
                    <x-ui.card>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-medium text-ink">{{ __('Run log') }}</h2>
                                <p class="mt-1 text-xs text-muted">{{ __('Last business-domain action') }}</p>
                            </div>

                            <button
                                type="button"
                                x-on:click="open = false"
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
        </div>
    @endif

    @if (count($available) > 0)
        <section class="space-y-2">
            <h2 class="text-lg font-semibold text-ink">{{ __('Available business domains') }}</h2>
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($available as $name => $entry)
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
                                wire:click="install('{{ $name }}')"
                                wire:confirm="{{ __('Install :name? This clones the repository and runs database migrations.', ['name' => $name]) }}"
                                wire:loading.attr="disabled"
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
                    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-2 gap-y-1 text-xs text-muted">
                        <dt class="font-medium text-ink">{{ __('Installed') }}</dt>
                        <dd>
                            @if ($installation)
                                <x-ui.datetime :value="$installation['occurred_at']" />
                            @else
                                {{ __('Not recorded') }}
                            @endif
                        </dd>
                        <dt class="font-medium text-ink">{{ __('By') }}</dt>
                        <dd>
                            @if ($installerLabel)
                                <span>{{ $installerLabel }}</span>
                                @if ($installerName && $installerEmail)
                                    <span class="text-muted">({{ $installerEmail }})</span>
                                @endif
                            @else
                                {{ __('Not recorded') }}
                            @endif
                        </dd>
                    </dl>

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
                                    wire:click="enable('{{ $domain['name'] }}')"
                                    wire:loading.attr="disabled"
                                >
                                    <span wire:loading.remove wire:target="enable('{{ $domain['name'] }}')">{{ __('Enable') }}</span>
                                    <span wire:loading wire:target="enable('{{ $domain['name'] }}')">{{ __('Enabling…') }}</span>
                                </x-ui.button>
                            @else
                                <x-ui.button
                                    size="sm"
                                    variant="secondary"
                                    wire:click="disable('{{ $domain['name'] }}')"
                                    wire:confirm="{{ __('Disable :name? Its pages, menus, and settings disappear until re-enabled; code and data are untouched.', ['name' => $domain['name']]) }}"
                                    wire:loading.attr="disabled"
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
                            >
                                <span wire:loading.remove wire:target="openUninstall('{{ $domain['name'] }}')">{{ __('Uninstall') }}</span>
                                <span wire:loading wire:target="openUninstall('{{ $domain['name'] }}')">{{ __('Opening…') }}</span>
                            </x-ui.button>
                        </div>
                    @endif

                    @if ($uninstallTarget === $domain['name'])
                        @php($phraseName = strtolower($domain['name']))
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
                            <x-ui.acknowledge-input
                                :phrase="'uninstall '.$phraseName"
                                wire:model="uninstallPhrase"
                                wire:keydown.enter="uninstall"
                            />
                            <div class="flex gap-2">
                                <x-ui.button
                                    size="sm"
                                    variant="danger"
                                    wire:click="uninstall"
                                    wire:loading.attr="disabled"
                                >
                                    <span wire:loading.remove wire:target="uninstall">{{ __('Uninstall') }}</span>
                                    <span wire:loading wire:target="uninstall">{{ __('Uninstalling…') }}</span>
                                </x-ui.button>
                                <x-ui.button
                                    size="sm"
                                    variant="secondary"
                                    wire:click="cancelUninstall"
                                    wire:loading.attr="disabled"
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
