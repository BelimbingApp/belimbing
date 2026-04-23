<div class="space-y-section-gap">
    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Modal and Confirmation Behavior')"
                component="<code>x-ui.modal</code>, <code>x-ui.button</code>, <code>x-ui.alert</code>"
            >
                {{ __('Overlays should interrupt cleanly: focused surface, clear dismissal, and actions that explain consequence before commitment.') }}
            </x-ui.catalog-section>

            <div class="flex flex-wrap gap-2">
                <x-ui.button variant="primary" wire:click="$set('demoModalOpen', true)">{{ __('Open Standard Modal') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="$set('demoConfirmOpen', true)">{{ __('Open Confirmation') }}</x-ui.button>
            </div>

            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Backdrop') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Background content should recede without disappearing entirely.') }}</p>
                </div>
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Dismissal') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Escape and click-outside behavior should be consistent unless the action is intentionally locked down.') }}</p>
                </div>
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Action Hierarchy') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Primary confirmation stays close to the message; cancellation remains obvious but calmer.') }}</p>
                </div>
            </div>
        </div>
    </x-ui.card>

    <x-ui.modal wire:model="demoModalOpen" class="max-w-lg">
        <div class="space-y-4 p-6">
            <div>
                <h2 class="text-lg font-medium text-ink">{{ __('Standard Modal') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Use a standard modal for secondary work that needs focus but does not deserve a full-page transition.') }}</p>
            </div>

            <x-ui.input id="ui-reference-modal-name" :label="__('Reference Name')" :placeholder="__('Compact and descriptive')" />
            <x-ui.textarea id="ui-reference-modal-note" :label="__('Notes')" rows="3">{{ __('Keep supporting copy short and operational.') }}</x-ui.textarea>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="$set('demoModalOpen', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="$set('demoModalOpen', false)">{{ __('Save') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <x-ui.modal wire:model="demoConfirmOpen" class="max-w-md">
        <div class="space-y-4 p-6">
            <div>
                <h2 class="text-lg font-medium text-ink">{{ __('Delete Reference') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Confirmation dialogs should make the consequence explicit and keep the action pair easy to parse at a glance.') }}</p>
            </div>

            <x-ui.alert variant="warning">
                {{ __('This demonstration does not delete data, but it shows the intended destructive confirmation hierarchy.') }}
            </x-ui.alert>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="$set('demoConfirmOpen', false)">{{ __('Keep Reference') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="$set('demoConfirmOpen', false)">{{ __('Delete') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>

