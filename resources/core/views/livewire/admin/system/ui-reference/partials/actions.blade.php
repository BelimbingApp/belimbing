<div class="space-y-section-gap">
    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Button Variants')"
                    component="<code>x-ui.button</code>"
                >
                    {{ __('Button emphasis should correspond to action importance. Accent is scarce by design, so primary actions remain visible.') }}
                </x-ui.catalog-section>

                <div class="space-y-3">
                    <div class="rounded-2xl border border-border-default bg-surface-subtle p-3">
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Normal (md)') }}</div>
                        <div class="mt-2 flex flex-wrap gap-2">
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
                    </div>

                    <div class="rounded-2xl border border-border-default bg-surface-subtle p-3">
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Compact (sm)') }}</div>
                        <p class="mt-1 text-xs text-muted">{{ __('Use compact buttons for dense tables, inline toolbars, and supporting actions where vertical rhythm is tight.') }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <x-ui.button variant="primary" size="sm">
                                <x-icon name="heroicon-o-check-circle" class="h-3.5 w-3.5" />
                                {{ __('Primary') }}
                            </x-ui.button>
                            <x-ui.button variant="secondary" size="sm">{{ __('Secondary') }}</x-ui.button>
                            <x-ui.button variant="ghost" size="sm">{{ __('Ghost') }}</x-ui.button>
                            <x-ui.button variant="outline" size="sm">{{ __('Outline') }}</x-ui.button>
                            <x-ui.button variant="danger" size="sm">{{ __('Danger') }}</x-ui.button>
                            <x-ui.button variant="primary" size="sm" disabled>{{ __('Disabled') }}</x-ui.button>
                        </div>
                    </div>

                    <p class="text-xs text-muted">{{ __('Standardize: default to normal buttons on primary forms and page headers; switch to compact only when the surrounding UI is already dense.') }}</p>
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
                <x-ui.catalog-section
                    :title="__('Icon Actions')"
                    component="<code>x-ui.icon-action</code>, <code>x-ui.icon-action-group</code>"
                >
                    {{ __('Use icon-only actions for dense rows and supporting operations. Pair them with sensible titles and screen-reader labels.') }}
                </x-ui.catalog-section>

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
