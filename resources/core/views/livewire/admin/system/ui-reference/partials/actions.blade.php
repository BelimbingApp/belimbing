<div class="space-y-section-gap">
    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Button Variants') }}</h2>
                    <p class="text-xs text-muted">{{ __('Button emphasis should correspond to action importance. Accent is scarce by design, so primary actions remain visible.') }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button variant="primary">
                        <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                        {{ __('Primary') }}
                    </x-ui.button>
                    <x-ui.button variant="secondary">{{ __('Secondary') }}</x-ui.button>
                    <x-ui.button variant="ghost">{{ __('Ghost') }}</x-ui.button>
                    <x-ui.button variant="outline">{{ __('Outline') }}</x-ui.button>
                    <x-ui.button variant="danger">{{ __('Danger') }}</x-ui.button>
                    <x-ui.button variant="primary" disabled>{{ __('Disabled') }}</x-ui.button>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button variant="primary">
                        <x-icon name="heroicon-o-arrow-path" class="h-4 w-4 animate-spin" />
                        {{ __('Saving...') }}
                    </x-ui.button>
                    <x-ui.button variant="danger-ghost">
                        <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                        {{ __('Destructive Ghost') }}
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Icon Actions') }}</h2>
                    <p class="text-xs text-muted">{{ __('Use icon-only actions for dense rows and supporting operations. Pair them with sensible titles and screen-reader labels.') }}</p>
                </div>

                <div class="flex items-center justify-between rounded-2xl border border-border-default bg-surface-card p-4">
                    <div>
                        <div class="text-sm font-medium text-ink">{{ __('Quarterly Supplier Review') }}</div>
                        <div class="text-xs text-muted">{{ __('Use grouped row actions when the record title should remain the scanning anchor.') }}</div>
                    </div>

                    <x-ui.icon-action-group>
                        <x-ui.icon-action icon="heroicon-o-eye" :label="__('View')" />
                        <x-ui.icon-action icon="heroicon-o-document-text" :label="__('Edit')" />
                        <x-ui.icon-action icon="heroicon-o-trash" :label="__('Delete')" />
                    </x-ui.icon-action-group>
                </div>

                <div class="rounded-2xl border border-border-default bg-surface-subtle p-4 text-xs text-muted">
                    {{ __('Use danger emphasis only on the control that actually destroys or irreversibly changes data. Supporting navigation should stay neutral.') }}
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
