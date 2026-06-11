<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-ink">{{ __('Domains') }}</h1>
        <p class="text-sm text-muted">{{ __('A fresh Belimbing install ships the Core modules only. Install the business domains you need; disable or uninstall them here later.') }}</p>
    </header>

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if (session('error'))
        <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
    @endif
    @if (session('command-log'))
        <x-ui.card>
            <h3 class="text-sm font-medium text-ink">{{ __('Command output') }}</h3>
            <pre class="mt-2 max-h-64 overflow-auto rounded bg-surface-subtle p-3 text-xs text-muted">{{ session('command-log') }}</pre>
        </x-ui.card>
    @endif

    @if (count($available) > 0)
        <section class="space-y-2">
            <h2 class="text-lg font-semibold text-ink">{{ __('Available domains') }}</h2>
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
        <h2 class="text-lg font-semibold text-ink">{{ __('Installed domains') }}</h2>
        <div class="grid gap-4 md:grid-cols-3">
            @forelse ($installed as $domain)
                <x-ui.card wire:key="domain-{{ $domain['name'] }}">
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
                                <x-ui.button size="sm" variant="secondary" wire:click="enable('{{ $domain['name'] }}')">
                                    {{ __('Enable') }}
                                </x-ui.button>
                            @else
                                <x-ui.button
                                    size="sm"
                                    variant="secondary"
                                    wire:click="disable('{{ $domain['name'] }}')"
                                    wire:confirm="{{ __('Disable :name? Its pages, menus, and settings disappear until re-enabled; code and data are untouched.', ['name' => $domain['name']]) }}"
                                >
                                    {{ __('Disable') }}
                                </x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="danger" wire:click="openUninstall('{{ $domain['name'] }}')">
                                {{ __('Uninstall') }}
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
                            <input
                                type="text"
                                wire:model="uninstallPhrase"
                                wire:keydown.enter="uninstall"
                                placeholder="uninstall {{ $phraseName }}"
                                class="w-full rounded border-border-default bg-surface-card text-sm"
                            />
                            @error('uninstallPhrase')
                                <div class="text-xs text-status-danger">{{ $message }}</div>
                            @enderror
                            <div class="flex gap-2">
                                <x-ui.button size="sm" variant="danger" wire:click="uninstall">
                                    {{ __('Uninstall') }}
                                </x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="cancelUninstall">
                                    {{ __('Cancel') }}
                                </x-ui.button>
                            </div>
                        </div>
                    @endif
                </x-ui.card>
            @empty
                <x-ui.card>
                    <div class="text-sm text-muted">{{ __('No non-Core domains are installed.') }}</div>
                </x-ui.card>
            @endforelse
        </div>
    </section>

    <section class="space-y-2">
        <h2 class="text-lg font-semibold text-ink">{{ __('Database residue') }}</h2>
        <p class="text-sm text-muted">{{ __('Anything no installed module claims — typically left by an uninstall that kept the database. Residue is safe to keep; reinstalling the domain adopts it again.') }}</p>

        @if (count($residue['orphanTables']) === 0 && count($residue['orphanLedger']) === 0 && count($residue['orphanSettings']) === 0)
            <x-ui.alert variant="success">
                {{ __('No residue found. Every table, migration entry, and setting belongs to installed code.') }}
            </x-ui.alert>
        @else
            @if (count($residue['orphanTables']) > 0)
                <x-ui.card>
                    <h3 class="font-medium text-ink">{{ __('Orphaned tables (:n)', ['n' => count($residue['orphanTables'])]) }}</h3>
                    <div class="mt-2 space-y-1">
                        @foreach ($residue['orphanTables'] as $orphan)
                            <label class="flex items-center gap-2 text-sm" wire:key="table-{{ $orphan['table'] }}">
                                <input type="checkbox" wire:model="selectedTables" value="{{ $orphan['table'] }}" @disabled(! $canManage) class="rounded border-border-default" />
                                <code>{{ $orphan['table'] }}</code>
                                <span class="text-xs text-muted">{{ __(':n row(s)', ['n' => $orphan['rows']]) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($canManage)
                        <x-ui.button variant="danger" size="sm" class="mt-3" wire:click="dropSelectedTables" wire:confirm="{{ __('Drop the selected tables permanently? Their data cannot be recovered.') }}">
                            {{ __('Drop selected tables') }}
                        </x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            @if (count($residue['orphanLedger']) > 0)
                <x-ui.card>
                    <h3 class="font-medium text-ink">{{ __('Stale migration ledger rows (:n)', ['n' => count($residue['orphanLedger'])]) }}</h3>
                    <p class="text-xs text-muted">{{ __('Ledger entries whose migration file is gone. Harmless to keep; prune them once their tables are dropped.') }}</p>
                    <div class="mt-2 space-y-1">
                        @foreach ($residue['orphanLedger'] as $migration)
                            <label class="flex items-center gap-2 text-sm" wire:key="ledger-{{ $migration }}">
                                <input type="checkbox" wire:model="selectedLedger" value="{{ $migration }}" @disabled(! $canManage) class="rounded border-border-default" />
                                <code class="text-xs">{{ $migration }}</code>
                            </label>
                        @endforeach
                    </div>
                    @if ($canManage)
                        <x-ui.button variant="danger" size="sm" class="mt-3" wire:click="pruneSelectedLedger" wire:confirm="{{ __('Remove the selected ledger rows?') }}">
                            {{ __('Prune selected rows') }}
                        </x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            @if (count($residue['orphanSettings']) > 0)
                <x-ui.card>
                    <h3 class="font-medium text-ink">{{ __('Orphaned settings (:n keys)', ['n' => count($residue['orphanSettings'])]) }}</h3>
                    <p class="text-xs text-muted">{{ __('Setting keys no installed module declares. Deleting removes the key across all scopes (global, company, employee).') }}</p>
                    <div class="mt-2 space-y-1">
                        @foreach ($residue['orphanSettings'] as $orphan)
                            <label class="flex items-center gap-2 text-sm" wire:key="setting-{{ $orphan['key'] }}">
                                <input type="checkbox" wire:model="selectedSettings" value="{{ $orphan['key'] }}" @disabled(! $canManage) class="rounded border-border-default" />
                                <code>{{ $orphan['key'] }}</code>
                                <span class="text-xs text-muted">{{ __(':n row(s)', ['n' => $orphan['rows']]) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($canManage)
                        <x-ui.button variant="danger" size="sm" class="mt-3" wire:click="deleteSelectedSettings" wire:confirm="{{ __('Delete the selected settings across all scopes?') }}">
                            {{ __('Delete selected settings') }}
                        </x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            @if ($canManage)
                <x-ui.card>
                    <label class="block text-sm font-medium text-ink" for="confirm-text">{{ __('Confirmation') }}</label>
                    <p class="text-xs text-muted">{{ __('Type DELETE to arm the buttons above. Cleanup is permanent.') }}</p>
                    <input id="confirm-text" type="text" wire:model="confirmText" placeholder="DELETE" class="mt-2 w-48 rounded border-border-default bg-surface-card text-sm" />
                    @error('confirmText')
                        <div class="mt-1 text-xs text-status-danger">{{ $message }}</div>
                    @enderror
                </x-ui.card>
            @else
                <div class="rounded-2xl border border-border-default bg-surface-subtle px-4 py-3 text-sm text-muted">
                    {{ __('You can view residue but need the domains manage capability to clean it up.') }}
                </div>
            @endif
        @endif
    </section>
</div>
