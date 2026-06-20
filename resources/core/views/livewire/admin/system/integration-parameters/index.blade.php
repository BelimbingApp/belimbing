<?php
use App\Base\Settings\Models\Setting;

/** @var \Illuminate\Pagination\LengthAwarePaginator<int, Setting> $parameters */
?>

<div>
    <x-slot name="title">{{ __('Integration Parameters') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Integration Parameters')"
            :subtitle="__('Key-value parameters for external integrations — Cloudflare, WeChat ingest, legacy AX, … — stored as global settings under integrations.<system>.<name>; code reads them by that key. Secret is a per-entry type: secrets are encrypted and write-only, text parameters stay readable. Configuration owned by a module (e.g. eBay) belongs on that module’s settings page, not here.')"
        />

        <x-ui.session-flash />

        <x-ui.card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="w-full max-w-xs">
                    <x-ui.search-input
                        id="integration-parameters-search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('Search keys…')"
                    />
                </div>

                <x-ui.button type="button" variant="primary" size="sm" wire:click="openAddModal">
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    {{ __('Add parameter') }}
                </x-ui.button>
            </div>

            @if ($parameters->isEmpty())
                <p class="text-sm text-muted">
                    {{ $search === '' ? __('No integration parameters stored yet.') : __('No parameters match “:search”.', ['search' => $search]) }}
                </p>
            @else
                <x-ui.table container="flush" :caption="__('Integration parameters')">
                    <x-slot name="head">
                        <tr>
                            <x-ui.sortable-th column="key" :sort-by="$sortBy" :sort-dir="$sortDir" :label="__('Key')" />
                            <x-ui.th>{{ __('Type') }}</x-ui.th>
                            <x-ui.th>{{ __('Description') }}</x-ui.th>
                            <x-ui.th>{{ __('Value') }}</x-ui.th>
                            <x-ui.sortable-th column="updated_at" :sort-by="$sortBy" :sort-dir="$sortDir" :label="__('Updated')" />
                        </tr>
                    </x-slot>

                    @foreach ($parameters as $row)
                        @php($data = $this->rowData($row))
                        {{-- The row is the edit affordance: click anywhere to open the entry modal. --}}
                        <tr
                            wire:key="integration-parameter-{{ $row->key }}"
                            wire:click="openEntry('{{ $row->key }}')"
                            class="cursor-pointer transition-colors hover:bg-surface-subtle"
                        >
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <span class="font-mono text-xs font-medium text-accent">{{ $row->key }}</span>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <x-ui.badge :variant="$data['secret'] ? 'warning' : 'default'">{{ $data['secret'] ? __('Secret') : __('Text') }}</x-ui.badge>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <span class="text-sm text-muted">{{ $data['description'] !== '' ? $data['description'] : '—' }}</span>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <span class="break-all font-mono text-xs {{ $data['secret'] ? 'text-muted' : 'text-ink' }}">{{ $data['display'] }}</span>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <span class="text-xs text-muted" title="{{ $row->updated_at?->format('Y-m-d H:i:s') }}">{{ $row->updated_at?->diffForHumans() }}</span>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <div class="mt-4">
                    {{ $parameters->links() }}
                </div>
            @endif
        </x-ui.card>

        {{-- Add modal --}}
        <x-ui.modal wire:model="addModalOpen" class="max-w-lg">
            <div class="space-y-4 p-6">
                <div>
                    <h3 class="text-base font-medium tracking-tight text-ink">{{ __('Add parameter') }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ __('Stored as integrations.<system>.<name> in global settings.') }}</p>
                </div>

                <form wire:submit="addParameter" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-ui.input
                            id="integration-parameter-system"
                            wire:model="newSystem"
                            :label="__('System')"
                            :placeholder="__('e.g. cloudflare')"
                            :error="$errors->first('newSystem')"
                        />
                        <x-ui.input
                            id="integration-parameter-name"
                            wire:model="newName"
                            :label="__('Name')"
                            :placeholder="__('e.g. api_token')"
                            :error="$errors->first('newName')"
                        />
                    </div>

                    <x-ui.select
                        id="integration-parameter-type"
                        wire:model.live="newType"
                        :label="__('Type')"
                        :help="__('Secrets are encrypted and never displayed again. Text stays readable.')"
                        :error="$errors->first('newType')"
                    >
                        <option value="secret">{{ __('Secret (encrypted, write-only)') }}</option>
                        <option value="text">{{ __('Text (readable)') }}</option>
                    </x-ui.select>

                    <x-ui.input
                        id="integration-parameter-description"
                        wire:model="newDescription"
                        :label="__('Description')"
                        :placeholder="__('Scope, owner, expiry…')"
                        :error="$errors->first('newDescription')"
                    />

                    @if ($newType === 'secret')
                        <x-ui.secret-input
                            id="integration-parameter-value-secret"
                            wire:model="newValue"
                            :label="__('Secret value')"
                            :error="$errors->first('newValue')"
                        />
                    @else
                        <x-ui.input
                            id="integration-parameter-value-text"
                            wire:model="newValue"
                            :label="__('Value')"
                            :error="$errors->first('newValue')"
                        />
                    @endif

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="$set('addModalOpen', false)">{{ __('Cancel') }}</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-icon name="heroicon-o-key" class="h-4 w-4" />
                            {{ __('Add parameter') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </x-ui.modal>

        {{-- Entry modal: edit value + description, or delete --}}
        <x-ui.modal wire:model="entryModalOpen" class="max-w-lg">
            <div class="space-y-4 p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="break-all font-mono text-sm font-medium text-ink">{{ $entryKey }}</h3>
                    </div>
                    <x-ui.badge :variant="$this->entryIsSecret() ? 'warning' : 'default'">{{ $this->entryIsSecret() ? __('Secret') : __('Text') }}</x-ui.badge>
                </div>

                <form wire:submit="saveEntry" class="space-y-4">
                    <x-ui.input
                        id="integration-parameter-entry-description"
                        wire:model="entryDescription"
                        :label="__('Description')"
                        :error="$errors->first('entryDescription')"
                    />

                    @if ($this->entryIsSecret())
                        <x-ui.secret-input
                            id="integration-parameter-entry-value"
                            wire:model="entryValue"
                            :label="__('Secret value')"
                            :has-value="true"
                            :error="$errors->first('entryValue')"
                        />
                    @else
                        <x-ui.input
                            id="integration-parameter-entry-value"
                            wire:model="entryValue"
                            :label="__('Value')"
                            :error="$errors->first('entryValue')"
                        />
                    @endif

                    <div class="flex items-center justify-between gap-2">
                        <x-ui.button
                            type="button"
                            variant="danger-ghost"
                            size="sm"
                            wire:click="deleteParameter"
                            wire:confirm="{{ __('Delete :key? Code reading this setting will start receiving null.', ['key' => $entryKey]) }}"
                        >
                            <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                            {{ __('Delete') }}
                        </x-ui.button>

                        <div class="flex gap-2">
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeEntry">{{ __('Cancel') }}</x-ui.button>
                            <x-ui.button type="submit" variant="primary" size="sm">{{ __('Save') }}</x-ui.button>
                        </div>
                    </div>
                </form>
            </div>
        </x-ui.modal>
    </div>
</div>
