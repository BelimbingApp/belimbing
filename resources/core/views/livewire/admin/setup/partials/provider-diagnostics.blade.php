<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>
<div>
    <div class="flex items-center gap-3 mb-3">
        <x-ui.button
            wire:click="testProvider"
            wire:loading.attr="disabled"
            variant="ghost"
            size="sm"
            :disabled="! $this->selectedProviderId || ! $this->selectedModelId"
        >
            <span wire:loading.remove wire:target="testProvider">
                <x-icon name="heroicon-o-signal" class="w-4 h-4" />
            </span>
            <span wire:loading wire:target="testProvider" class="animate-spin">
                <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
            </span>
            <span wire:loading.remove wire:target="testProvider">{{ __('Test Provider') }}</span>
            <span wire:loading wire:target="testProvider">{{ __('Testing...') }}</span>
        </x-ui.button>

        @if (! $this->selectedProviderId || ! $this->selectedModelId)
            <span class="text-xs text-muted">{{ __('Select a provider and model first') }}</span>
        @endif
    </div>

    @if ($this->providerTestResult !== null)
        @if ($this->providerTestResult['connected'])
            <x-ui.alert variant="success">
                <span class="flex flex-col gap-1">
                    <span class="font-medium">{{ __('Connection successful') }}</span>
                    <span class="text-xs opacity-80">
                        {{ $this->providerTestResult['provider_name'] }} · {{ $this->providerTestResult['model'] }}
                        @if ($this->providerTestResult['latency_ms'] !== null)
                            · {{ $this->providerTestResult['latency_ms'] }} ms
                        @endif
                    </span>
                </span>
            </x-ui.alert>
        @else
            <x-ui.alert variant="danger">
                <span class="flex flex-col gap-1">
                    <span class="font-medium">{{ __('Connection failed') }}</span>
                    @if ($this->providerTestResult['user_message'])
                        <span class="text-xs opacity-80">{{ $this->providerTestResult['user_message'] }}</span>
                    @endif
                    @if ($this->providerTestResult['hint'])
                        <span class="text-xs opacity-60">{{ __('Hint:') }} {{ $this->providerTestResult['hint'] }}</span>
                    @endif
                    <span class="text-xs opacity-60">
                        {{ $this->providerTestResult['provider_name'] }} · {{ $this->providerTestResult['model'] }}
                        @if ($this->providerTestResult['error_type'])
                            · {{ $this->providerTestResult['error_type'] }}
                        @endif
                    </span>
                </span>
            </x-ui.alert>
        @endif
    @endif
</div>
