<?php
/** @var string $label */
/** @var string $providerKey */
/** @var string $authType */
/** @var string $scope */
?>
<div class="flex items-center gap-1">
    <span>{{ $label }}</span>
    <x-ui.help
        wire:click.stop="openProviderHelp('{{ $providerKey }}', '{{ $authType }}', '{{ $scope }}')"
        title="{{ __('Setup & troubleshooting') }}"
    />
</div>
