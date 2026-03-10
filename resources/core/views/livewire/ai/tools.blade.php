<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Tools $this */
?>
<div>
    <x-slot name="title">{{ $toolName ? __('Tools') . ' — ' . $toolName : __('Tools') }}</x-slot>

    @if($toolName)
        <livewire:ai.tools.workspace :tool-name="$toolName" :key="'workspace-' . $toolName" />
    @else
        <livewire:ai.tools.catalog />
    @endif
</div>
