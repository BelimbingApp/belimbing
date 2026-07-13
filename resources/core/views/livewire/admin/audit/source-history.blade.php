<div>
    @if ($canViewAuditHistory)
        @if ($iconOnly)
            <x-ui.icon-action icon="heroicon-o-clock" :label="$buttonLabelText" :title="$buttonLabelText" wire:click="open" />
        @else
            <x-ui.button type="button" variant="ghost" wire:click="open">
                <x-icon name="heroicon-o-clock" class="w-4 h-4" />
                {{ $buttonLabelText }}
            </x-ui.button>
        @endif
    @endif

    @include('livewire.admin.audit.partials.source-history-drawer')
    @include('livewire.admin.audit.partials.trace-drawer')
</div>
