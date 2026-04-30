<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\PricingOverrides $this */
?>
<div>
    <x-slot name="title">{{ __('Pricing Overrides') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Pricing Overrides')"
            :subtitle="__('Maintain explicit USD per 1M token rates for custom contracts, self-hosted models, and upstream pricing corrections.')"
        />

        <x-ui.card>
            <form wire:submit="saveOverride" class="space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-medium text-ink">
                            {{ $editingOverrideId !== null ? __('Edit Override') : __('New Override') }}
                        </h3>
                        <p class="mt-1 text-xs text-muted">{{ __('Rates are stored as USD per 1M tokens to match provider pricing pages.') }}</p>
                    </div>

                    @if ($editingOverrideId !== null)
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelEdit">
                            {{ __('Cancel') }}
                        </x-ui.button>
                    @endif
                </div>

                <div class="grid gap-3 lg:grid-cols-5">
                    <x-ui.input
                        id="pricing-override-provider"
                        wire:model="provider"
                        :label="__('Provider')"
                        :placeholder="__('openai')"
                        :error="$errors->first('provider')"
                        :help="__('Leave blank for a provider-agnostic model override.')"
                    />
                    <x-ui.input
                        id="pricing-override-model"
                        wire:model="model"
                        :label="__('Model')"
                        :placeholder="__('gpt-5.4')"
                        required
                        :error="$errors->first('model')"
                    />
                    <x-ui.input
                        id="pricing-override-input-usd-per-million"
                        wire:model="inputUsdPerMillionTokens"
                        :label="__('Input USD/1M tokens')"
                        :placeholder="__('1.000000')"
                        required
                        :error="$errors->first('inputUsdPerMillionTokens')"
                    />
                    <x-ui.input
                        id="pricing-override-cached-usd-per-million"
                        wire:model="cachedInputUsdPerMillionTokens"
                        :label="__('Cached USD/1M tokens')"
                        :placeholder="__('0.100000')"
                        :error="$errors->first('cachedInputUsdPerMillionTokens')"
                    />
                    <x-ui.input
                        id="pricing-override-output-usd-per-million"
                        wire:model="outputUsdPerMillionTokens"
                        :label="__('Output USD/1M tokens')"
                        :placeholder="__('2.000000')"
                        required
                        :error="$errors->first('outputUsdPerMillionTokens')"
                    />
                </div>

                <x-ui.textarea
                    id="pricing-override-reason"
                    wire:model="reason"
                    rows="2"
                    :label="__('Reason')"
                    :placeholder="__('Enterprise contract, self-hosted GPU rate, or upstream correction.')"
                    :error="$errors->first('reason')"
                />

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" variant="primary">
                        <x-icon name="heroicon-o-check" class="h-4 w-4" />
                        {{ $editingOverrideId !== null ? __('Save Changes') : __('Create Override') }}
                    </x-ui.button>
                    <x-action-message on="pricing-override-created" class="text-xs text-status-success">{{ __('Override created.') }}</x-action-message>
                    <x-action-message on="pricing-override-updated" class="text-xs text-status-success">{{ __('Override updated.') }}</x-action-message>
                    <x-action-message on="pricing-override-deleted" class="text-xs text-status-success">{{ __('Override deleted.') }}</x-action-message>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-sm font-medium text-ink">{{ __('Existing Overrides') }}</h3>
                <div class="w-full sm:max-w-xs">
                    <x-ui.search-input
                        id="pricing-overrides-search"
                        wire:model.live.debounce.250ms="search"
                        :placeholder="__('Search provider, model, or reason...')"
                    />
                </div>
            </div>

            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Provider') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Model') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Input USD/1M') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Cached USD/1M') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Output USD/1M') }}</th>
                            <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Reason') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($overrides as $override)
                            <tr wire:key="pricing-override-{{ $override->id }}">
                                <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-muted">{{ $override->provider ?? __('Any') }}</td>
                                <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $override->model }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-right font-mono text-xs text-muted">{{ $override->input_usd_per_million_tokens }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-right font-mono text-xs text-muted">{{ $override->cached_input_usd_per_million_tokens ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-right font-mono text-xs text-muted">{{ $override->output_usd_per_million_tokens }}</td>
                                <td class="hidden max-w-sm truncate px-table-cell-x py-table-cell-y text-xs text-muted lg:table-cell">{{ $override->reason ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-right">
                                    <x-ui.icon-action-group>
                                        <x-ui.icon-action
                                            icon="heroicon-o-pencil"
                                            :label="__('Edit pricing override')"
                                            :title="__('Edit')"
                                            wire:click="editOverride({{ $override->id }})"
                                        />
                                        <x-ui.icon-action
                                            icon="heroicon-o-trash"
                                            :label="__('Delete pricing override')"
                                            :title="__('Delete')"
                                            wire:click="deleteOverride({{ $override->id }})"
                                            wire:confirm="{{ __('Delete this pricing override?') }}"
                                        />
                                    </x-ui.icon-action-group>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __('No pricing overrides match the current filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $overrides->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
