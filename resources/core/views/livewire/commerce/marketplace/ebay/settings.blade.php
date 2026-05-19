<?php

use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings;

/** @var Settings $this */
/** @var array<string, mixed> $group */
/** @var string $groupId */
?>

<div>
    <x-slot name="title">{{ $pageTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$pageTitle" :subtitle="$pageSubtitle" />

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Connection test') }}</h2>
                        <x-ui.badge :variant="$this->connectionTestBadgeVariant()">
                            {{ __(\Illuminate\Support\Str::headline($this->connectionTest['status'] ?? 'not tested')) }}
                        </x-ui.badge>
                    </div>
                    <p class="text-sm text-muted">
                        {{ __('Verify the saved eBay credentials, OAuth grant, selected environment, and read-only Inventory API access before syncing listings or orders.') }}
                    </p>

                    @if ($this->connectionTest['message'] ?? null)
                        <x-ui.alert :variant="$this->connectionTestAlertVariant()" class="mt-3">
                            {{ $this->connectionTest['message'] }}
                        </x-ui.alert>
                    @else
                        <p class="text-sm text-muted">{{ __('Not tested yet.') }}</p>
                    @endif

                    @if ($this->connectionTest['tested_at'] ?? null)
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Last checked') }}</dt>
                                <dd class="mt-1 text-ink">{{ \Illuminate\Support\Carbon::parse($this->connectionTest['tested_at'])->diffForHumans() }}</dd>
                            </div>
                            @if ($this->connectionTest['http_status'] ?? null)
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('HTTP status') }}</dt>
                                    <dd class="mt-1 font-mono text-ink">{{ $this->connectionTest['http_status'] }}</dd>
                                </div>
                            @endif
                            @if ($this->connectionTest['exchange_id'] ?? null)
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Exchange') }}</dt>
                                    <dd class="mt-1 font-mono text-ink">{{ $this->connectionTest['exchange_id'] }}</dd>
                                </div>
                            @endif
                        </dl>
                    @endif
                </div>

                <div class="flex shrink-0 flex-wrap gap-2">
                    <x-ui.button type="button" variant="primary" wire:click="connect" wire:loading.attr="disabled" wire:target="connect">
                        <x-icon name="heroicon-o-link" class="h-4 w-4" />
                        {{ $this->connectButtonLabel() }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="outline" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                        <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="testConnection">{{ __('Test connection') }}</span>
                        <span wire:loading wire:target="testConnection">{{ __('Testing...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>

        <form wire:submit="save" class="space-y-6">
            @if (($group['fields'] ?? []) === [])
                <x-ui.card>
                    <p class="text-sm text-muted">{{ __('No editable settings are registered for this page.') }}</p>
                </x-ui.card>
            @else
                <x-ui.card wire:key="settings-group-{{ $groupId }}">
                    @if ($group['help_title'] ?? null)
                        <div class="mb-5" x-data="{ helpOpen: false }">
                            <div class="flex items-center gap-2">
                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Setup fields') }}</h2>
                                <x-ui.help size="lg" @click="helpOpen = !helpOpen" ::aria-expanded="helpOpen" />
                            </div>

                            <div
                                x-cloak
                                x-show="helpOpen"
                                x-transition:enter="transition-all ease-out duration-200 motion-reduce:duration-0"
                                x-transition:enter-start="max-h-0 opacity-0"
                                x-transition:enter-end="max-h-96 opacity-100"
                                x-transition:leave="transition-all ease-in duration-150 motion-reduce:duration-0"
                                x-transition:leave-start="max-h-96 opacity-100"
                                x-transition:leave-end="max-h-0 opacity-0"
                                class="mt-3 overflow-hidden rounded-2xl border border-border-default bg-surface-subtle text-sm text-muted"
                                @click="helpOpen = false"
                                role="note"
                                aria-label="{{ __('Click to dismiss') }}"
                            >
                                <div class="space-y-3 p-4">
                                    <div>
                                        <p class="text-sm font-medium text-ink">{{ __($group['help_title']) }}</p>
                                        @if ($group['help_intro'] ?? null)
                                            <p class="mt-1 text-sm text-muted">{!! __($group['help_intro']) !!}</p>
                                        @endif
                                    </div>

                                    @if (($group['help_steps'] ?? []) !== [])
                                        <ol class="list-decimal space-y-1.5 pl-5 text-sm text-muted">
                                            @foreach ($group['help_steps'] as $step)
                                                @if (is_array($step))
                                                    <li>
                                                        {{ __($step['before_link'] ?? '') }}
                                                        <a href="{{ $step['url'] }}" target="_blank" rel="noreferrer" class="text-accent hover:underline">
                                                            {{ __($step['link_label']) }}
                                                        </a>
                                                        {!! __($step['after_link'] ?? '') !!}
                                                    </li>
                                                @else
                                                    <li>{!! __($step) !!}</li>
                                                @endif
                                            @endforeach
                                        </ol>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    @include('livewire.settings.partials.fields-grid', ['group' => $group])
                </x-ui.card>
            @endif

            <div class="flex items-center gap-3">
                <x-ui.button type="submit" variant="primary">
                    <x-icon name="heroicon-o-check" class="h-4 w-4" />
                    {{ __('Save Settings') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
