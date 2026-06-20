<?php

use App\Base\Settings\Livewire\SettingsForm;

/** @var SettingsForm $this */
/** @var array<string, mixed> $group */
/** @var string $groupId */
?>

<div>
    <x-slot name="title">{{ $pageTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$pageTitle" :subtitle="$pageSubtitle" />

        <x-ui.session-flash />

        <form wire:submit="save" class="space-y-6">
            @if (($group['fields'] ?? []) === [])
                <x-ui.card>
                    <p class="text-sm text-muted">{{ __('No editable settings are registered for this page.') }}</p>
                </x-ui.card>
            @else
                <x-ui.card wire:key="settings-group-{{ $groupId }}">
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
