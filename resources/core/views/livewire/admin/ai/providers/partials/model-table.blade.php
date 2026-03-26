<?php

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use Illuminate\Support\Collection;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/**
 * Shared model management table for AI providers.
 *
 * Used by both the main Providers page (expanded row) and the ProviderSetup
 * flow (inline after connection). Requires parent Livewire component to use
 * ManagesModels, ManagesSync, ManagesProviderHelp, and FormatsDisplayValues traits.
 *
 * @var AiProvider $provider
 * @var Collection<AiProviderModel> $models
 */
?>
<div class="bg-surface-subtle/30 border-t border-border-default px-8 py-3">
    <div class="flex items-center justify-between mb-2">
        <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Models') }}</span>
        <div class="flex items-center gap-1">
            <x-ui.button
                variant="ghost"
                size="sm"
                class="flex-row flex-nowrap"
                wire:click.stop="syncProviderModels({{ $provider->id }})"
                wire:target="syncProviderModels({{ $provider->id }})"
                wire:loading.attr="disabled"
            >
                <span
                    wire:loading.remove
                    wire:target="syncProviderModels({{ $provider->id }})"
                    class="flex flex-row flex-nowrap items-center gap-1.5"
                >
                    <x-icon name="heroicon-o-arrow-path" class="inline-block h-3.5 w-3.5 shrink-0 align-middle" />
                    <span class="whitespace-nowrap">{{ __('Update Models') }}</span>
                </span>
                <span
                    wire:loading
                    wire:target="syncProviderModels({{ $provider->id }})"
                    class="flex flex-row flex-nowrap items-center gap-1.5"
                    aria-live="polite"
                >
                    <x-icon name="heroicon-o-arrow-path" class="inline-block h-3.5 w-3.5 shrink-0 align-middle motion-safe:animate-spin" />
                    <span class="whitespace-nowrap">{{ __('Update Models') }}</span>
                </span>
            </x-ui.button>
            <x-ui.button variant="ghost" size="sm" wire:click.stop="openCreateModel({{ $provider->id }})">
                <x-icon name="heroicon-o-plus" class="w-3.5 h-3.5" />
                {{ __('Add Model') }}
            </x-ui.button>
        </div>
    </div>

    @if($syncMessage)
        <div
            class="mb-2 px-3 py-1.5 bg-surface-subtle rounded text-sm text-muted"
            x-data="{ show: true }"
            x-init="setTimeout(() => { show = false; $wire.set('syncMessage', null) }, 4000)"
            x-show="show"
            x-transition.opacity
        >
            {{ $syncMessage }}
        </div>
    @endif

    @if($syncError && $syncErrorProviderId === $provider->id)
        @php $helpAdvice = app(\App\Base\AI\Providers\Help\ProviderHelpRegistry::class)->get($provider->name, $provider->auth_type ?? 'api_key')->connectionErrorAdvice(); @endphp
        <div class="mb-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3">
            <div class="flex items-start justify-between gap-2">
                <div class="flex items-start gap-2 min-w-0">
                    <x-icon name="heroicon-o-exclamation-circle" class="w-4 h-4 text-red-500 dark:text-red-400 mt-0.5 shrink-0" />
                    <div class="min-w-0">
                        <p class="text-sm text-red-700 dark:text-red-300 font-medium">{{ $syncError }}</p>
                        <p class="text-xs text-red-600 dark:text-red-400 mt-0.5">{{ $helpAdvice }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <button
                        type="button"
                        wire:click.stop="openProviderHelp('{{ $provider->name }}', '{{ $provider->auth_type ?? 'api_key' }}')"
                        class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200 underline whitespace-nowrap"
                    >
                        {{ __('Get help') }}
                    </button>
                    <button
                        wire:click.stop="clearSyncError"
                        class="p-0.5 rounded text-red-400 hover:text-red-600 dark:hover:text-red-200 hover:bg-red-100 dark:hover:bg-red-800/50"
                        type="button"
                        title="{{ __('Dismiss') }}"
                        aria-label="{{ __('Dismiss error') }}"
                    >
                        <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5" />
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if($models->count() > 0)
        <table class="min-w-full divide-y divide-border-default text-sm">
            <thead class="bg-surface-subtle/80">
                <tr>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Model ID') }}</th>
                    <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cost Override Input $/1M') }}</th>
                    <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cost Override Output $/1M') }}</th>
                    <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cache Read $/1M') }}</th>
                    <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cache Write $/1M') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-center text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Available') }}</th>
                </tr>
            </thead>
            <tbody class="bg-surface-card divide-y divide-border-default">
                @foreach($models as $model)
                    <tr wire:key="model-{{ $model->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink font-mono">
                            <div class="flex items-center gap-1.5">
                                @if($model->is_default)
                                    <span class="text-accent" title="{{ __('Default model') }}" aria-label="{{ __('Default model') }}">★</span>
                                @else
                                    <button
                                        wire:click="setDefaultModel({{ $model->id }})"
                                        class="text-muted hover:text-accent transition-colors"
                                        type="button"
                                        title="{{ __('Set as default') }}"
                                        aria-label="{{ __('Set as default model') }}"
                                    >☆</button>
                                @endif
                                <span>{{ $model->model_id }}</span>
                            </div>
                        </td>
                        @php $cost = $model->cost_override ?? []; @endphp
                        @foreach(['input', 'output', 'cache_read', 'cache_write'] as $costField)
                            <td
                                class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right"
                                x-data="{ editing: false, value: '{{ $cost[$costField] ?? '' }}' }"
                            >
                                <template x-if="!editing">
                                    <button
                                        type="button"
                                        @click="editing = true; $nextTick(() => $refs.input.select())"
                                        class="w-full text-right cursor-pointer hover:text-ink transition-colors"
                                        title="{{ __('Click to edit') }}"
                                    >
                                        {{ $this->formatCost($cost[$costField] ?? null) }}
                                    </button>
                                </template>
                                <template x-if="editing">
                                    <input
                                        x-ref="input"
                                        type="number"
                                        step="0.000001"
                                        min="0"
                                        x-model="value"
                                        @blur="editing = false; $wire.updateModelCost({{ $model->id }}, '{{ $costField }}', value)"
                                        @keydown.enter="editing = false; $wire.updateModelCost({{ $model->id }}, '{{ $costField }}', value)"
                                        @keydown.escape="editing = false"
                                        class="w-24 text-right text-sm tabular-nums px-1 py-0.5 border border-border-input rounded bg-surface-card text-ink focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                    />
                                </template>
                            </td>
                        @endforeach
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap" @click.stop>
                            <div class="flex justify-center">
                            <x-ui.checkbox
                                id="model-active-{{ $model->id }}"
                                :checked="$model->is_active"
                                wire:click="toggleModelActive({{ $model->id }})"
                                aria-label="{{ $model->is_active ? __('Deactivate :model', ['model' => $model->model_id]) : __('Activate :model', ['model' => $model->model_id]) }}"
                            />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="text-sm text-muted py-4 text-center">{{ __('No models registered for this provider.') }}</p>
    @endif
</div>
