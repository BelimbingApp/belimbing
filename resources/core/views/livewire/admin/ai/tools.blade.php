<?php
/** @var \App\Modules\Core\AI\Livewire\Tools $this */
?>
<div>
    <x-slot name="title">{{ $toolName ? __('Tools') . ' — ' . $toolName : __('Tools') }}</x-slot>

    @if($toolName)
        <livewire:admin.ai.tools.workspace :tool-name="$toolName" :key="'workspace-' . $toolName" />
    @else
        <livewire:admin.ai.tools.catalog />
    @endif
</div>
