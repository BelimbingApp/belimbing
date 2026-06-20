<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-ink">{{ __('Database Residue') }}</h1>
        <p class="text-sm text-muted">{{ __('Everything listed here exists only in the database — the code that created it is gone. That happens when migration files are deleted or renamed during development, or when a domain is uninstalled but its data kept. Keeping residue is harmless; cleanup below is permanent and only ever needed for tidiness.') }}</p>
    </header>

    <x-ui.session-flash />

    @if (count($residue['orphanTables']) === 0 && count($residue['orphanLedger']) === 0 && count($residue['orphanSettings']) === 0)
        <x-ui.alert variant="success">
            {{ __('No residue found. Every table, migration entry, and setting belongs to installed code.') }}
        </x-ui.alert>
    @else
        @if (count($residue['orphanTables']) > 0)
            <x-ui.card>
                <h3 class="font-medium text-ink">{{ __('Orphaned tables (:n)', ['n' => count($residue['orphanTables'])]) }}</h3>
                <p class="text-xs text-muted">{{ __('Tables no migration file on disk creates anymore. Dropping deletes the table and its data permanently. If a table belongs to an uninstalled domain you may reinstall later, keep it — the domain adopts it again on reinstall.') }}</p>
                <div class="mt-2 space-y-1">
                    @foreach ($residue['orphanTables'] as $orphan)
                        <label class="flex items-center gap-2 text-sm" wire:key="table-{{ $orphan['table'] }}">
                            <input type="checkbox" wire:model.live="selectedTables" value="{{ $orphan['table'] }}" @disabled(! $canManage) class="rounded border-border-default" />
                            <code>{{ $orphan['table'] }}</code>
                            <span class="text-xs text-muted">{{ __(':n row(s)', ['n' => $orphan['rows']]) }}</span>
                        </label>
                    @endforeach
                </div>
                @if ($canManage && $armed && count($selectedTables) > 0)
                    <x-ui.button variant="danger" size="sm" class="mt-3" wire:click="dropSelectedTables">
                        {{ __('Permanently drop :n table(s)', ['n' => count($selectedTables)]) }}
                    </x-ui.button>
                @endif
            </x-ui.card>
        @endif

        @if (count($residue['orphanLedger']) > 0)
            <x-ui.card>
                <h3 class="font-medium text-ink">{{ __('Stale migration records (:n)', ['n' => count($residue['orphanLedger'])]) }}</h3>
                <p class="text-xs text-muted">{{ __('Laravel records every migration file it has run in the migrations table. These records point to files that no longer exist — for example, schema changes folded back into their original create migration. Pruning deletes only the record itself; no files, tables, or data are touched.') }}</p>
                <div class="mt-2 space-y-1">
                    @foreach ($residue['orphanLedger'] as $migration)
                        <label class="flex items-center gap-2 text-sm" wire:key="ledger-{{ $migration }}">
                            <input type="checkbox" wire:model.live="selectedLedger" value="{{ $migration }}" @disabled(! $canManage) class="rounded border-border-default" />
                            <code class="text-xs">{{ $migration }}</code>
                        </label>
                    @endforeach
                </div>
                @if ($canManage && $armed && count($selectedLedger) > 0)
                    <x-ui.button variant="danger" size="sm" class="mt-3" wire:click="pruneSelectedLedger">
                        {{ __('Permanently remove :n migration record(s)', ['n' => count($selectedLedger)]) }}
                    </x-ui.button>
                @endif
            </x-ui.card>
        @endif

        @if (count($residue['orphanSettings']) > 0)
            <x-ui.card>
                <h3 class="font-medium text-ink">{{ __('Orphaned settings (:n keys)', ['n' => count($residue['orphanSettings'])]) }}</h3>
                <p class="text-xs text-muted">{{ __('Setting keys no installed module declares — either as an editable field or a runtime claim in its Config/settings.php. Deleting removes the key across all scopes (global, company, employee).') }}</p>
                <div class="mt-2 space-y-1">
                    @foreach ($residue['orphanSettings'] as $orphan)
                        <label class="flex items-center gap-2 text-sm" wire:key="setting-{{ $orphan['key'] }}">
                            <input type="checkbox" wire:model.live="selectedSettings" value="{{ $orphan['key'] }}" @disabled(! $canManage) class="rounded border-border-default" />
                            <code>{{ $orphan['key'] }}</code>
                            <span class="text-xs text-muted">{{ __(':n row(s)', ['n' => $orphan['rows']]) }}</span>
                        </label>
                    @endforeach
                </div>
                @if ($canManage && $armed && count($selectedSettings) > 0)
                    <x-ui.button variant="danger" size="sm" class="mt-3" wire:click="deleteSelectedSettings">
                        {{ __('Permanently delete :n setting key(s)', ['n' => count($selectedSettings)]) }}
                    </x-ui.button>
                @endif
            </x-ui.card>
        @endif

        @if ($canManage)
            <x-ui.card>
                <div class="max-w-md">
                    <x-ui.acknowledge-input
                        id="confirm-text"
                        :phrase="\App\Base\Database\Livewire\Residue\Index::CONFIRM_PHRASE"
                        :label="__('Acknowledgment')"
                        :help="__('Select items above, then type :phrase to reveal the delete buttons. Cleanup is permanent.', ['phrase' => \App\Base\Database\Livewire\Residue\Index::CONFIRM_PHRASE])"
                        wire:model.live.debounce.250ms="confirmText"
                    />
                </div>
            </x-ui.card>
        @else
            <div class="rounded-2xl border border-border-default bg-surface-subtle px-4 py-3 text-sm text-muted">
                {{ __('You can view residue but need the residue manage capability to clean it up.') }}
            </div>
        @endif
    @endif
</div>
