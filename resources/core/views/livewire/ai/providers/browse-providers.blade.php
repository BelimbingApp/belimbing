<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Providers\BrowseProviders $this */
?>
<div>
    <x-slot name="title">{{ __('Browse Providers') }}</x-slot>

    @if($wizardStep === 'connect')
        <livewire:ai.providers.connect-wizard :initial-forms="$connectForms" />
    @else
        <livewire:ai.providers.catalog />
    @endif
</div>
