<x-ui.button variant="ghost" wire:click="verifyConnection" wire:loading.attr="disabled" wire:target="verifyConnection">
    <x-icon name="heroicon-o-shield-check" class="w-4 h-4" />
    {{ __('Verify connection') }}
</x-ui.button>
<x-ui.button variant="ghost" wire:click="disconnect">
    <x-icon name="heroicon-o-link-slash" class="w-4 h-4" />
    {{ __('Disconnect') }}
</x-ui.button>
