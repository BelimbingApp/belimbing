<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/**
 * Web Search tool configuration partial.
 *
 * Multi-provider management with drag-to-reorder priority,
 * plus any generic config fields (e.g. cache_ttl_minutes).
 *
 * Expected Livewire properties: $webSearchProviders, $availableProviders,
 * $metadata, $configValues, $configSaved, $configSaveError.
 */
?>
<x-ui.card>
    <h3 class="text-base font-semibold text-ink mb-2">{{ __('Configuration') }}</h3>
    <p class="text-xs text-muted mb-3">{{ __('Configure search providers in priority order. The tool tries each enabled provider and falls back on failure.') }}</p>

    <form wire:submit="saveConfig" class="space-y-4">
        {{-- Provider list with drag-to-reorder --}}
        <div
            x-data="{
                _dragIdx: null,
                _dropIdx: null,

                dragStart(idx, event) {
                    this._dragIdx = idx;
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', idx);
                },
                dragOver(idx, event) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                    this._dropIdx = idx;
                },
                dragLeave() {
                    this._dropIdx = null;
                },
                drop(idx) {
                    if (this._dragIdx === null || this._dragIdx === idx) {
                        this._dragIdx = null;
                        this._dropIdx = null;
                        return;
                    }
                    const count = $wire.webSearchProviders.length;
                    const order = [...Array(count).keys()];
                    const [moved] = order.splice(this._dragIdx, 1);
                    order.splice(idx, 0, moved);
                    $wire.reorderWebSearchProviders(order);
                    this._dragIdx = null;
                    this._dropIdx = null;
                },
                dragEnd() {
                    this._dragIdx = null;
                    this._dropIdx = null;
                },
            }"
        >
            <div class="flex items-center justify-between mb-2">
                <h4 class="text-xs text-muted uppercase tracking-wider font-semibold">{{ __('Providers') }}</h4>
                @if(count($webSearchProviders) < count($availableProviders))
                    <button
                        type="button"
                        wire:click="addWebSearchProvider"
                        class="inline-flex items-center gap-1 text-xs text-accent hover:text-accent/80 transition-colors"
                    >
                        <x-icon name="heroicon-o-plus-circle" class="w-3.5 h-3.5" />
                        {{ __('Add Provider') }}
                    </button>
                @endif
            </div>

            @if(count($webSearchProviders) === 0)
                <p class="text-sm text-muted italic">{{ __('No providers configured. Add one to get started.') }}</p>
            @else
                <div class="space-y-2">
                    @foreach($webSearchProviders as $index => $provider)
                        <div
                            class="rounded-lg border p-3 transition-all"
                            :class="{
                                'border-accent bg-accent/5': _dropIdx === {{ $index }},
                                'border-border-default bg-surface-subtle': _dropIdx !== {{ $index }},
                                'opacity-40': _dragIdx === {{ $index }},
                            }"
                            draggable="true"
                            x-on:dragstart="dragStart({{ $index }}, $event)"
                            x-on:dragover="dragOver({{ $index }}, $event)"
                            x-on:dragleave="dragLeave()"
                            x-on:drop="drop({{ $index }})"
                            x-on:dragend="dragEnd()"
                        >
                            <div class="flex items-center gap-2 mb-2">
                                {{-- Drag handle --}}
                                <span class="cursor-grab active:cursor-grabbing text-muted hover:text-ink" title="{{ __('Drag to reorder') }}">
                                    <x-icon name="heroicon-o-bars-3" class="w-4 h-4" />
                                </span>

                                {{-- Priority badge --}}
                                <span class="text-[10px] uppercase tracking-wider font-semibold {{ $index === 0 ? 'text-status-success' : 'text-muted' }}">
                                    #{{ $index + 1 }}
                                </span>

                                {{-- Provider select --}}
                                <select
                                    wire:model.live="webSearchProviders.{{ $index }}.name"
                                    class="text-sm bg-transparent border-0 p-0 text-ink font-medium focus:ring-0 cursor-pointer"
                                >
                                    @foreach($availableProviders as $providerKey => $providerLabel)
                                        <option value="{{ $providerKey }}">{{ $providerLabel }}</option>
                                    @endforeach
                                </select>

                                <div class="flex-1"></div>

                                {{-- Enabled toggle --}}
                                <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        wire:model.live="webSearchProviders.{{ $index }}.enabled"
                                        value="1"
                                        class="w-3.5 h-3.5 rounded border border-border-input bg-surface-card accent-accent focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                    >
                                    <span class="text-xs text-muted">{{ __('On') }}</span>
                                </label>

                                {{-- Remove button --}}
                                <button
                                    type="button"
                                    wire:click="removeWebSearchProvider({{ $index }})"
                                    class="text-muted hover:text-status-danger transition-colors"
                                    title="{{ __('Remove') }}"
                                >
                                    <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                                </button>
                            </div>

                            {{-- API key input --}}
                            <div class="w-full">
                                <x-ui.input
                                    id="web-search-provider-{{ $index }}-api-key"
                                    type="password"
                                    wire:model="webSearchProviders.{{ $index }}.api_key"
                                    placeholder="{{ $provider['has_key'] ? ($provider['key_preview'] !== '' ? $provider['key_preview'] : __('Key saved · enter to replace')) : __('Enter API key') }}"
                                    autocomplete="off"
                                    size="sm"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Generic config fields (cache_ttl_minutes, etc.) --}}
        @foreach($metadata->configFields as $field)
            @php
                $showWhenMatch = true;
                if ($field->showWhen) {
                    [$showKey, $showValue] = explode('=', $field->showWhen, 2);
                    $showWhenMatch = data_get($configValues, $showKey, '') === $showValue;
                }
            @endphp

            @if($showWhenMatch)
                @if($field->type === 'boolean')
                    @php
                        $fieldId = 'config-field-'.$field->key;
                    @endphp
                    <div class="space-y-1">
                        <label for="{{ $fieldId }}" class="block text-[11px] uppercase tracking-wider font-semibold text-muted">
                            {{ $field->label }}
                        </label>
                        <div class="inline-flex items-center gap-2 cursor-pointer">
                            <input
                                id="{{ $fieldId }}"
                                type="checkbox"
                                wire:model="configValues.{{ $field->key }}"
                                value="1"
                                class="w-4 h-4 rounded border border-border-input bg-surface-card accent-accent focus:ring-2 focus:ring-accent focus:ring-offset-2"
                            >
                            <span class="text-sm text-ink">{{ __('Enabled') }}</span>
                        </div>
                    </div>
                @else
                    <x-ui.input
                        id="web-search-config-{{ $field->key }}"
                        type="text"
                        wire:model="configValues.{{ $field->key }}"
                        label="{{ $field->label }}"
                    />
                @endif

                @if($field->help)
                    <p class="text-xs text-muted -mt-1.5">{{ $field->help }}</p>
                @endif
            @endif
        @endforeach

        <x-ui.button type="submit" variant="primary" size="sm" class="w-full">
            {{ __('Save Configuration') }}
        </x-ui.button>

        @if($configSaved)
            @if($configSaveError)
                <x-ui.alert variant="warning" :title="$configSaved">
                    <p class="text-xs opacity-75">{{ __('Please review the configuration fields above and try saving again.') }}</p>
                </x-ui.alert>
            @else
                <p class="text-xs text-status-success text-center">{{ $configSaved }}</p>
            @endif
        @endif
    </form>
</x-ui.card>
