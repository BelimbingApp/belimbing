<?php

use App\Modules\Core\AI\Livewire\Providers\ImageProviderSetup;

/** @var ImageProviderSetup $this */
?>
<div>
    <x-ui.modal wire:model="show" class="max-w-lg">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-1">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ $this->isConfigured ? __('Edit :provider key', ['provider' => $displayName]) : __('Add :provider key', ['provider' => $displayName]) }}</h3>
                <button wire:click="$set('show', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>
            <p class="text-sm text-muted mb-4">{{ __('Store this provider\'s credentials. No models to discover.') }}</p>

            @if ($providerKey)
                <div class="mb-4 space-y-3 rounded-xl border border-border-default bg-surface-subtle p-3">
                    @foreach ($this->endpointFields as $field)
                        @include('livewire.admin.ai.providers.partials.image-setup-field', [
                            'field' => $field,
                            'providerKey' => $providerKey,
                            'configured' => $configured,
                        ])
                    @endforeach

                    <div class="flex items-start gap-2 text-xs">
                        <span class="shrink-0 font-medium text-ink">{{ __('API endpoint') }}</span>
                        <code class="break-all text-muted">{{ $this->apiEndpoint }}</code>
                    </div>

                    @if ($this->keyUrl)
                        <div class="text-xs">
                            <x-ui.link kind="external" href="{{ $this->keyUrl }}">
                                {{ __(':provider API key', ['provider' => $displayName]) }}
                            </x-ui.link>
                        </div>
                    @endif
                </div>
            @endif

            <form wire:submit="save" class="space-y-4">
                @foreach ($this->credentialFields as $field)
                    @include('livewire.admin.ai.providers.partials.image-setup-field', [
                        'field' => $field,
                        'providerKey' => $providerKey,
                        'configured' => $configured,
                    ])
                @endforeach

                <div class="flex items-center justify-end gap-2 pt-2">
                    <x-ui.button variant="ghost" type="button" wire:click="$set('show', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                        {{ $this->isConfigured ? __('Update key') : __('Save key') }}
                    </x-ui.button>
                </div>
            </form>
        </div>
    </x-ui.modal>

    {{-- Remove-credentials confirmation (the Vision tab's "disconnect") --}}
    <x-ui.modal wire:model="showRemoveConfirm" class="max-w-sm">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Remove :provider key', ['provider' => $displayName]) }}</h3>
                <button wire:click="$set('showRemoveConfirm', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <p class="text-sm text-muted mb-4">{{ __('This deletes the stored credentials for :provider. You can add it again anytime.', ['provider' => $displayName]) }}</p>

            <div class="flex items-center justify-end gap-2">
                <x-ui.button variant="ghost" type="button" wire:click="$set('showRemoveConfirm', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="remove" wire:loading.attr="disabled" wire:target="remove">{{ __('Remove key') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
