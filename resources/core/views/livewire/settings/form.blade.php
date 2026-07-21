<?php

use App\Base\Settings\Livewire\SettingsForm;

/** @var SettingsForm $this */
/** @var list<array{id: string, config: array<string, mixed>}> $groups */
/** @var bool $multiGroup */
/** @var string|null $pageHelp */
/** @var string $pageHelpLabel */
?>

<div>
    <x-slot name="title">{{ $pageTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$pageTitle" :subtitle="$pageSubtitle" :help-label="$pageHelpLabel">
            @if ($pageHelp !== null)
                <x-slot name="help">
                    <p class="max-w-3xl leading-6 text-ink">{{ $pageHelp }}</p>
                </x-slot>
            @endif
        </x-ui.page-header>

        <x-ui.session-flash />

        @php($hasFields = collect($groups)->contains(fn (array $group): bool => ($group['config']['fields'] ?? []) !== []))

        <form wire:submit="save" class="space-y-6">
            @if (! $hasFields)
                <x-ui.card>
                    <p class="text-sm text-muted">{{ __('No editable settings are registered for this page.') }}</p>
                </x-ui.card>
            @elseif ($multiGroup)
                <x-ui.tabs :tabs="collect($groups)->map(fn (array $group): array => [
                    'id' => $group['id'],
                    'label' => __($group['config']['label'] ?? $group['id']),
                ])->all()">
                    @foreach ($groups as $group)
                        <x-ui.tab :id="$group['id']">
                            <x-ui.card wire:key="settings-group-{{ $group['id'] }}">
                                @if ($group['config']['description'] ?? null)
                                    <p class="mb-5 text-sm leading-6 text-muted">{{ __($group['config']['description']) }}</p>
                                @endif
                                @if ($group['config']['view'] ?? null)
                                    @include($group['config']['view'], ['group' => $group['config']])
                                @else
                                    @include('livewire.settings.partials.fields-grid', ['group' => $group['config']])
                                @endif
                            </x-ui.card>
                            @if (! ($group['config']['autosave'] ?? false))
                                <div class="mt-6 flex items-center gap-3">
                                    <x-ui.button type="submit" variant="primary">
                                        <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                        {{ __('Save Settings') }}
                                    </x-ui.button>
                                </div>
                            @endif
                        </x-ui.tab>
                    @endforeach
                </x-ui.tabs>
            @else
                @php($group = $groups[0])
                <x-ui.card wire:key="settings-group-{{ $group['id'] }}">
                    @if ($group['config']['view'] ?? null)
                        @include($group['config']['view'], ['group' => $group['config']])
                    @else
                        @include('livewire.settings.partials.fields-grid', ['group' => $group['config']])
                    @endif
                </x-ui.card>
            @endif

            @if (! $multiGroup)
                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" variant="primary">
                        <x-icon name="heroicon-o-check" class="h-4 w-4" />
                        {{ __('Save Settings') }}
                    </x-ui.button>
                </div>
            @endif
        </form>
    </div>
</div>
