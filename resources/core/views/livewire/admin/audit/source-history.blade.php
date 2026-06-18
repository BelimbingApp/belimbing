<div>
    @if ($canViewAuditHistory)
        <x-ui.button type="button" variant="ghost" wire:click="open">
            <x-icon name="heroicon-o-clock" class="w-4 h-4" />
            {{ $buttonLabelText }}
        </x-ui.button>
    @endif

    @include('livewire.admin.audit.partials.source-history-drawer')
    @include('livewire.admin.audit.partials.trace-drawer')
</div>
