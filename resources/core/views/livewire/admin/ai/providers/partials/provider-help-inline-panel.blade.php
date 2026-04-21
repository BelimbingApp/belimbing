<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var string $matchKey */
/** @var string $scope */
/** @var string $wireKey */
/** @var string $providerName */
/** @var int $colspan */
/** @var string|null $visibilityExpression */
?>
@if($helpProviderKey === $matchKey && $helpPanelScope === $scope)
    {{-- wire:key must live on the rendered <tr>, not a wrapper, because this partial is included inside <tbody>.
         We still avoid <x-...> here because Livewire injects <?php before wire:key and breaks ComponentTagCompiler. --}}
    @php
        $providerHelpRowAttributes = ['wire:key' => $wireKey];

        if (filled($visibilityExpression ?? null)) {
            $providerHelpRowAttributes['x-show'] = $visibilityExpression;
        }

        $providerHelpRowAttrs = new \Illuminate\View\ComponentAttributeBag($providerHelpRowAttributes);
    @endphp
    @include('components.ai.provider-help-panel', [
        'providerName' => $providerName,
        'help' => $this->activeProviderHelp(),
        'colspan' => $colspan,
        'rowAttributes' => $providerHelpRowAttrs,
    ])
@endif
