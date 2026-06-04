<?php

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use Illuminate\Support\Collection;

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
                    <span class="whitespace-nowrap">{{ __('Sync Models') }}</span>
                </span>
                <span
                    wire:loading
                    wire:target="syncProviderModels({{ $provider->id }})"
                    class="flex flex-row flex-nowrap items-center gap-1.5"
                    aria-live="polite"
                >
                    <x-icon name="heroicon-o-arrow-path" class="inline-block h-3.5 w-3.5 shrink-0 align-middle motion-safe:animate-spin" />
                    <span class="whitespace-nowrap">{{ __('Sync Models') }}</span>
                </span>
            </x-ui.button>
            <x-ui.button variant="ghost" size="sm" wire:click.stop="openCreateModel({{ $provider->id }})">
                <x-icon name="heroicon-o-plus" class="w-3.5 h-3.5" />
                {{ __('Add Model') }}
            </x-ui.button>
        </div>
    </div>

    @if($syncMessage && $syncMessageProviderId === $provider->id)
        <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded bg-surface-subtle px-3 py-1.5 text-sm text-muted">
            <button
                type="button"
                wire:click="clearSyncMessage"
                class="min-w-0 flex-1 text-left cursor-pointer rounded-sm text-muted hover:bg-surface-subtle/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface-subtle motion-reduce:transition-none"
                title="{{ __('Dismiss') }}"
            >
                <span>{{ $syncMessage }}</span>
            </button>
            @if($syncExchangeId && $syncExchangeProviderId === $provider->id)
                <a
                    href="{{ route('admin.integration.outbound-exchanges.show', $syncExchangeId) }}"
                    class="shrink-0 text-accent hover:underline"
                    wire:click.stop
                    wire:navigate
                >
                    {{ __('Outbound exchange') }}
                </a>
            @endif
        </div>
    @endif

    @if($syncError && $syncErrorProviderId === $provider->id)
        @php $helpAdvice = app(\App\Base\AI\Providers\Help\ProviderHelpRegistry::class)->get($provider->name, $provider->auth_type->value)->connectionErrorAdvice(); @endphp
        <div class="mb-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3">
            <div class="flex items-start justify-between gap-2">
                <div class="flex items-start gap-2 min-w-0">
                    <x-icon name="heroicon-o-exclamation-circle" class="w-4 h-4 text-red-500 dark:text-red-400 mt-0.5 shrink-0" />
                    <div class="min-w-0">
                        <p class="text-sm text-red-700 dark:text-red-300 font-medium">{{ $syncError }}</p>
                        <p class="text-xs text-red-600 dark:text-red-400 mt-0.5">{{ $helpAdvice }}</p>
                        @if($syncExchangeId && $syncExchangeProviderId === $provider->id)
                            <a href="{{ route('admin.integration.outbound-exchanges.show', $syncExchangeId) }}" class="mt-1 inline-flex text-xs text-red-700 dark:text-red-300 hover:underline" wire:navigate>
                                {{ __('Inspect exchange :id', ['id' => $syncExchangeId]) }}
                            </a>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <button
                        type="button"
                        wire:click.stop="openProviderHelp('{{ $provider->name }}', '{{ $provider->auth_type->value }}', 'connected')"
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
        <x-ui.table container="plain" :caption="__('Provider models')">
            <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('Model ID') }}</x-ui.th>
                    <x-ui.th align="right" class="hidden lg:table-cell">{{ __('Cost Override Input $/1M') }}</x-ui.th>
                    <x-ui.th align="right" class="hidden lg:table-cell">{{ __('Cost Override Output $/1M') }}</x-ui.th>
                    <x-ui.th align="right" class="hidden lg:table-cell">{{ __('Cache Read $/1M') }}</x-ui.th>
                    <x-ui.th align="right" class="hidden lg:table-cell">{{ __('Cache Write $/1M') }}</x-ui.th>
                    <x-ui.th align="center">{{ __('Access') }}</x-ui.th>
                </tr>
            </x-slot>

                @foreach($models as $model)
                    <tr wire:key="model-{{ $model->id }}">
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink font-mono">
                            {{ $model->model_id }}
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
                            @php $hasExecutionOverrides = is_array($model->execution_controls) && $model->execution_controls !== []; @endphp
                            <div class="flex items-center justify-center gap-1">
                                @if($model->is_default)
                                    <span
                                        class="text-accent shrink-0 inline-flex h-4 w-4 items-center justify-center"
                                        title="{{ $model->is_active ? __('Default for this provider.') : __('Make available to use this default.') }}"
                                        aria-label="{{ __('Default model') }}"
                                    >★</span>
                                @else
                                    <button
                                        wire:click.stop="setDefaultModel({{ $model->id }})"
                                        class="text-muted hover:text-accent transition-colors shrink-0 inline-flex h-4 w-4 items-center justify-center"
                                        type="button"
                                        title="{{ __('Set default for this provider.') }}"
                                        aria-label="{{ __('Set as default model') }}"
                                    >☆</button>
                                @endif
                                <x-ui.checkbox
                                    id="model-active-{{ $model->id }}"
                                    :checked="$model->is_active"
                                    wire:click="toggleModelActive({{ $model->id }})"
                                    title="{{ $model->is_active ? __('Withdraw from Agent pickers.') : __('Make available to Agent pickers.') }}"
                                    aria-label="{{ $model->is_active ? __('Deactivate :model', ['model' => $model->model_id]) : __('Activate :model', ['model' => $model->model_id]) }}"
                                />
                                <button
                                    type="button"
                                    wire:click.stop="openModelExecutionControls({{ $model->id }})"
                                    @class([
                                        'inline-flex items-center p-1 rounded transition-colors',
                                        'text-accent hover:text-accent hover:bg-surface-subtle' => $hasExecutionOverrides,
                                        'text-muted hover:text-ink hover:bg-surface-subtle' => ! $hasExecutionOverrides,
                                    ])
                                    title="{{ $hasExecutionOverrides ? __('Edit run settings — overrides on.') : __('Edit run settings.') }}"
                                    aria-label="{{ $hasExecutionOverrides ? __('Edit execution controls for :model (overrides active)', ['model' => $model->model_id]) : __('Edit execution controls for :model', ['model' => $model->model_id]) }}"
                                >
                                    <x-icon name="heroicon-o-adjustments-horizontal" class="w-4 h-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach

        </x-ui.table>
    @else
        <p class="text-sm text-muted py-4 text-center">{{ __('No models registered for this provider.') }}</p>
    @endif
</div>
