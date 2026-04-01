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
    <x-ai.provider-help-panel
        wire:key="{{ $wireKey }}"
        :provider-name="$providerName"
        :help="$this->activeProviderHelp()"
        :colspan="$colspan"
        @if(filled($visibilityExpression ?? null))
            x-show="{{ $visibilityExpression }}"
        @endif
    />
@endif