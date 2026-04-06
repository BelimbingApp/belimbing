<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>
@php
    $testAction = $testAction ?? 'testProvider';
    $testProviderId = $testProviderId ?? $this->selectedProviderId;
    $testModelId = $testModelId ?? $this->selectedModelId;
    $testResult = $testResult ?? $this->providerTestResult;
@endphp
<div>
    <div class="flex items-center gap-3 mb-3">
        <x-ui.button
            wire:click="{{ $testAction }}"
            wire:loading.attr="disabled"
            variant="ghost"
            size="sm"
            :disabled="! $testProviderId || ! $testModelId"
        >
            <span wire:loading.remove wire:target="{{ $testAction }}">
                <x-icon name="heroicon-o-signal" class="w-4 h-4" />
            </span>
            <span wire:loading wire:target="{{ $testAction }}" class="animate-spin">
                <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
            </span>
            <span wire:loading.remove wire:target="{{ $testAction }}">{{ __('Test Provider') }}</span>
            <span wire:loading wire:target="{{ $testAction }}">{{ __('Testing...') }}</span>
        </x-ui.button>

        @if (! $testProviderId || ! $testModelId)
            <span class="text-xs text-muted">{{ __('Select a provider and model first') }}</span>
        @endif
    </div>

    @if ($testResult !== null)
        @if ($testResult['connected'])
            <x-ui.alert variant="success">
                <span class="flex flex-col gap-1">
                    <span class="font-medium">{{ __('Connection successful') }}</span>
                    <span class="text-xs opacity-80">
                        {{ $testResult['provider_name'] }} · {{ $testResult['model'] }}
                        @if ($testResult['latency_ms'] !== null)
                            · {{ $testResult['latency_ms'] }} ms
                        @endif
                    </span>
                </span>
            </x-ui.alert>
        @else
            <x-ui.alert variant="danger">
                <span class="flex flex-col gap-1">
                    <span class="font-medium">{{ __('Connection failed') }}</span>
                    @if ($testResult['user_message'])
                        <span class="text-xs opacity-80">{{ $testResult['user_message'] }}</span>
                    @endif
                    @if ($testResult['hint'])
                        <span class="text-xs opacity-60">{{ __('Hint:') }} {{ $testResult['hint'] }}</span>
                    @endif
                    <span class="text-xs opacity-60">
                        {{ $testResult['provider_name'] }} · {{ $testResult['model'] }}
                        @if ($testResult['error_type'])
                            · {{ $testResult['error_type'] }}
                        @endif
                    </span>
                </span>
            </x-ui.alert>
        @endif
    @endif
</div>
