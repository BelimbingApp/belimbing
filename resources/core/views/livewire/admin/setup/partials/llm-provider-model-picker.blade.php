<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>
@php
    // Included via @include — avoid @props (component-only). Callers pass context, providers, models.
    $selectedProviderId = $selectedProviderId ?? null;
    $providerBinding = $providerBinding ?? 'selectedProviderId';
    $modelBinding = $modelBinding ?? 'selectedModelId';
    $providerErrorKey = $providerErrorKey ?? 'selectedProviderId';
    $modelErrorKey = $modelErrorKey ?? 'selectedModelId';
@endphp

<div>
    {{-- Provider picker --}}
    <div class="mb-3">
        <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Provider') }}</span>
        @if ($errors->has($providerErrorKey))
            <p class="text-xs text-status-danger mt-1">{{ $errors->first($providerErrorKey) }}</p>
        @endif

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-1 mt-2">
            @foreach($providers as $provider)
                @php($providerId = 'llm-provider-'.$context.'-'.$provider->id)
                <label for="{{ $providerId }}" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-surface-subtle cursor-pointer">
                    <input
                        id="{{ $providerId }}"
                        type="radio"
                        wire:model.live="{{ $providerBinding }}"
                        value="{{ $provider->id }}"
                        class="w-4 h-4 rounded-full border border-border-input bg-surface-card accent-accent focus:ring-2 focus:ring-accent focus:ring-offset-2"
                    >
                    <span class="text-sm text-ink truncate" title="{{ $provider->display_name }}">{{ $provider->display_name }}</span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- Model picker --}}
    @if ($selectedProviderId)
        <div class="mb-3">
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Model') }}</span>
            @if ($errors->has($modelErrorKey))
                <p class="text-xs text-status-danger mt-1">{{ $errors->first($modelErrorKey) }}</p>
            @endif

            @if ($models->isEmpty())
                <p class="text-xs text-muted mt-2">
                    {{ __('No active models found for this provider. Add one in provider connections, then come back.') }}
                </p>
            @else
                <div x-data="{ modelFilter: '' }" class="mt-2">
                    @if ($models->count() > 6)
                        @php($modelFilterId = 'llm-model-filter-'.$context)
                        <x-ui.search-input
                            :id="$modelFilterId"
                            x-model="modelFilter"
                            placeholder="{{ __('Search models...') }}"
                            class="mb-2"
                        />
                    @endif

                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-1 max-h-64 overflow-y-auto">
                        @foreach($models as $model)
                            @php($modelId = 'llm-model-'.$context.'-'.$model->id)
                            <label
                                for="{{ $modelId }}"
                                x-show='!modelFilter || @json(\Illuminate\Support\Str::lower($model->model_id)).includes(modelFilter.toLowerCase())'
                                class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-surface-subtle cursor-pointer"
                            >
                                <input
                                    id="{{ $modelId }}"
                                    type="radio"
                                    wire:model.live="{{ $modelBinding }}"
                                    value="{{ $model->model_id }}"
                                    class="w-4 h-4 rounded-full border border-border-input bg-surface-card accent-accent focus:ring-2 focus:ring-accent focus:ring-offset-2 shrink-0"
                                >
                                <span class="text-sm text-ink font-mono truncate" title="{{ $model->model_id }}">{{ $model->model_id }}</span>
                                @if ($model->is_default)
                                    <x-ui.badge variant="accent" class="shrink-0">{{ __('default') }}</x-ui.badge>
                                @endif
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>

