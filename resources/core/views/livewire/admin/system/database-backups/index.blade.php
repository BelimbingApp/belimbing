<?php
/** @var \App\Base\Database\Livewire\Backups\Index $this */
?>
<div>
    <x-slot name="title">{{ __('Database Backups') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Database Backups')" :subtitle="__('Encrypted database backups stored on the configured Laravel disk')">
            <x-slot name="actions">
                @if ($canCreate && $enabled)
                    <div x-data="{ running: false }" class="inline-flex">
                        <x-ui.button
                            variant="primary"
                            type="button"
                            x-on:click="running = true; $wire.runBackup().finally(() => { running = false })"
                            x-bind:disabled="running"
                        >
                            <span x-show="!running" class="inline-flex items-center gap-2">
                                <x-icon name="heroicon-o-arrow-down-tray" class="w-4 h-4" />
                                {{ __('Run Backup Now') }}
                            </span>
                            <span x-show="running" class="inline-flex items-center gap-2">
                                <x-icon name="heroicon-o-arrow-path" class="w-4 h-4 animate-spin" />
                                {{ __('Running...') }}
                            </span>
                        </x-ui.button>
                    </div>
                @endif
            </x-slot>
            <x-slot name="help">
                <p><code>blb:db:backup</code> runs the same pipeline as the CLI: dump (driver-aware) → encrypt (mode-aware) → upload to disk → write sidecar manifest.</p>
                <p class="mt-2">Restore is a manual CLI process. For <code>none</code>-mode SQLite artifacts: copy the <code>.bak</code> file to the target path. For PostgreSQL: <code>pg_restore --no-owner --no-privileges --clean --if-exists --dbname={target} {artifact}</code>. For <code>app-key</code>-mode artifacts, use <code>blb:db:backup:restore</code> — see <code>docs/runbooks/database-backup.md</code> for the full restore drill.</p>
                <p class="mt-2">See <code>docs/runbooks/database-backup.md</code> for tier selection and APP_KEY rotation procedures.</p>
            </x-slot>
        </x-ui.page-header>

        @if (! $enabled)
            <x-ui.alert variant="warning">
                {{ __('Backups are disabled (config: backup.enabled=false). Managed-DB deployments rely on provider snapshots.') }}
            </x-ui.alert>
        @endif

        @if ($statusMessage)
            <x-ui.alert :variant="$statusVariant ?? 'info'">
                {{ $statusMessage }}
            </x-ui.alert>
        @endif

        @if ($mode === 'none')
            <x-ui.alert variant="warning">
                {{ __('Encryption mode is "none": artifacts are plaintext. Acceptable only for deployments with no sensitive data.') }}
            </x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Backup Configuration') }}</h3>
            @if ($canManageBackup)
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.edit-in-place.select
                        :label="__('Enabled')"
                        :value="$enabled ? '1' : '0'"
                        field="backup.enabled"
                        :help="__('Disable on managed-DB deployments that rely on provider snapshots.')"
                    >
                        <x-slot name="read">
                            <span class="text-sm text-ink">{{ $enabled ? __('Yes') : __('No') }}</span>
                        </x-slot>
                        <option value="1">{{ __('Enabled') }}</option>
                        <option value="0">{{ __('Disabled') }}</option>
                    </x-ui.edit-in-place.select>
                    <x-ui.edit-in-place.text
                        :label="__('Disk')"
                        :value="$disk"
                        field="backup.disk"
                        monospace
                        :help="__('Laravel filesystem disk for artifacts. Changing the disk does not migrate existing artifacts — old backups remain on the previous disk.')"
                    />
                    <x-ui.edit-in-place.text
                        :label="__('Path Prefix')"
                        :value="$pathPrefix"
                        field="backup.path_prefix"
                        monospace
                        :help="__('Directory within the disk. Files are stored as <code>{prefix}/{environment}/{timestamp}-{id}.bak</code>.')"
                    />
                    <x-ui.edit-in-place.select
                        :label="__('Encryption')"
                        :value="$mode"
                        field="backup.encryption.mode"
                        :help="__('Mode applied to every new artifact. Built-in: <code>none</code> (plaintext) and <code>app-key</code> (HKDF-SHA-256&nbsp;+&nbsp;XChaCha20-Poly1305 keyed from APP_KEY). Extensions register additional modes via <code>EncryptionModeRegistry::register()</code> in their <code>ServiceProvider::boot()</code>.')"
                    >
                        <x-slot name="read">
                            <span class="text-sm text-ink font-mono">{{ $mode }}</span>
                        </x-slot>
                        @foreach ($encryptionModes as $encMode)
                            <option value="{{ $encMode }}">{{ $encMode }}</option>
                        @endforeach
                    </x-ui.edit-in-place.select>
                    <x-ui.edit-in-place.text
                        :label="__('Keep Days')"
                        :value="(string) $keepDays"
                        field="backup.retention.keep_days"
                        tabular
                        inputmode="numeric"
                        maxlength="6"
                        help="Delete backups older than <span x-text='val'></span> days when <code>--prune</code> is passed. <code>0</code> disables age-based pruning."
                    />
                    <x-ui.edit-in-place.text
                        :label="__('Keep Count')"
                        :value="(string) $keepCount"
                        field="backup.retention.keep_count"
                        tabular
                        inputmode="numeric"
                        maxlength="6"
                        help="Always retain at least <span x-text='val'></span> recent backups regardless of age."
                    />
                </dl>
            @else
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Enabled') }}</dt>
                        <dd class="text-ink">{{ $enabled ? __('Yes') : __('No') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Disk') }}</dt>
                        <dd class="text-ink font-mono">{{ $disk }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Path Prefix') }}</dt>
                        <dd class="text-ink font-mono">{{ $pathPrefix }}</dd>
                    </div>
                    <div x-data="{ h: false }">
                        <dt class="flex items-center gap-1 text-[11px] uppercase tracking-wider font-semibold text-muted">
                            <span>{{ __('Encryption') }}</span>
                            <button
                                type="button"
                                class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full transition-colors focus:outline-none text-muted/70 hover:text-muted"
                                :class="h ? 'text-accent' : ''"
                                aria-label="{{ __('Field help') }}"
                                :aria-expanded="h.toString()"
                                @click="h = !h"
                            >
                                <x-icon name="heroicon-o-question-mark-circle" class="h-3.5 w-3.5" />
                            </button>
                        </dt>
                        <dd class="text-ink font-mono">{{ $mode }}</dd>
                        <dd
                            x-cloak
                            x-show="h"
                            x-transition:enter="transition-all ease-out duration-200"
                            x-transition:enter-start="max-h-0 opacity-0"
                            x-transition:enter-end="max-h-24 opacity-100"
                            x-transition:leave="transition-all ease-in duration-150"
                            x-transition:leave-start="max-h-24 opacity-100"
                            x-transition:leave-end="max-h-0 opacity-0"
                            class="mt-0.5 overflow-hidden text-xs font-normal normal-case leading-5 tracking-normal text-muted"
                        >
                            <span class="block">{!! __('Mode applied to every new artifact. Built-in: <code>none</code> (plaintext) and <code>app-key</code> (HKDF-SHA-256&nbsp;+&nbsp;XChaCha20-Poly1305 keyed from APP_KEY). Extensions register additional modes via <code>EncryptionModeRegistry::register()</code> in their <code>ServiceProvider::boot()</code>.') !!}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Keep Days') }}</dt>
                        <dd class="text-ink tabular-nums">{{ $keepDays }}</dd>
                        <dd class="mt-0.5 text-xs text-muted">{!! __('Delete backups older than <strong>:n</strong> days when <code>--prune</code> is passed.', ['n' => $keepDays]) !!}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Keep Count') }}</dt>
                        <dd class="text-ink tabular-nums">{{ $keepCount }}</dd>
                        <dd class="mt-0.5 text-xs text-muted">{!! __('Always retain at least <strong>:n</strong> recent backups regardless of age.', ['n' => $keepCount]) !!}</dd>
                    </div>
                </dl>
            @endif
        </x-ui.card>

        <x-ui.card>
            <x-ui.table container="flush" :caption="__('Database backups')">
                    <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Backup ID') }}</x-ui.th>
                            <x-ui.th>{{ __('Driver') }}</x-ui.th>
                            <x-ui.th>{{ __('Encryption') }}</x-ui.th>
                            <x-ui.th>{{ __('Finished') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Size (bytes)') }}</x-ui.th>
                            <x-ui.th>{{ __('Trigger') }}</x-ui.th>
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                        </tr>
                    </x-slot>

                        @forelse ($rows as $row)
                            <tr wire:key="backup-{{ $row['manifest_path'] }}">
                                <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $row['backup_id'] }}</td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-ui.badge>{{ $row['driver'] }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-ui.badge :variant="$row['encryption_mode'] === 'none' ? 'warning' : 'success'">
                                        {{ $row['encryption_mode'] }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-muted tabular-nums">
                                    <x-ui.datetime :value="$row['finished_at'] ?: null" />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">
                                    {{ number_format($row['size_bytes']) }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-muted">
                                    {{ $row['trigger'] }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-ui.badge :variant="$row['status'] === 'success' ? 'success' : 'danger'">
                                        {{ $row['status'] }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-ui.icon-action-group>
                                        <x-ui.icon-action
                                            wire:click="verify('{{ $row['manifest_path'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="verify"
                                            icon="heroicon-o-shield-check"
                                            :label="__('Verify integrity')"
                                        />
                                        @if ($canDelete)
                                            <x-ui.icon-action
                                                wire:click="delete('{{ $row['manifest_path'] }}')"
                                                wire:confirm="{{ __('Delete this backup? This is permanent.') }}"
                                                icon="heroicon-o-trash"
                                                :label="__('Delete backup')"
                                            />
                                        @endif
                                    </x-ui.icon-action-group>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-table-cell-x py-table-cell-y text-center text-muted">
                                    {{ __('No backups yet. Run one above or via php artisan blb:db:backup.') }}
                                </td>
                            </tr>
                        @endforelse

            </x-ui.table>
        </x-ui.card>
    </div>
</div>
