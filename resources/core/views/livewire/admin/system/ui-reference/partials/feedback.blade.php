<div class="space-y-section-gap">
    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Alert Variants') }}</h2>
                    <p class="text-xs text-muted">{{ __('Inline alerts stay in document flow and are appropriate for page-level or form-level feedback that should remain visible until the user moves on.') }}</p>
                </div>

                <div class="space-y-3">
                    <x-ui.alert variant="success">{{ __('Changes were saved successfully.') }}</x-ui.alert>
                    <x-ui.alert variant="info">{{ __('Use info for neutral operational guidance, not for promotional emphasis.') }}</x-ui.alert>
                    <x-ui.alert variant="warning">{{ __('Warning signals work that needs attention but has not failed yet.') }}</x-ui.alert>
                    <x-ui.alert variant="danger">{{ __('Danger should be reserved for failures, destructive consequences, or blocked work.') }}</x-ui.alert>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div
                x-data="{
                    demo(variant, message) { $dispatch('notify', { variant, message }) },
                    stack() {
                        this.demo('success', @js(__('Default model updated.')))
                        setTimeout(() => this.demo('warning', @js(__('Availability sync needs attention.'))), 150)
                        setTimeout(() => this.demo('error', @js(__('The channel could not be reached.'))), 300)
                    },
                }"
                class="space-y-4"
            >
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Notifications') }}</h2>
                    <p class="text-xs text-muted">{{ __('Notifications confirm same-page actions at the top-right of the viewport, two-thirds wide so they are noticeable wherever the action was triggered. Persistence is severity-tiered: errors and warnings stay until dismissed; success and info auto-dismiss. Trigger them from a Livewire component with the InteractsWithNotifications trait ($this->notify()).') }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button variant="primary" @click="demo('success', @js(__('Default model updated.')))">{{ __('Success') }}</x-ui.button>
                    <x-ui.button variant="secondary" @click="demo('warning', @js(__('Availability sync needs attention.')))">{{ __('Warning (sticky)') }}</x-ui.button>
                    <x-ui.button variant="secondary" @click="demo('error', @js(__('The channel could not be reached.')))">{{ __('Error (sticky)') }}</x-ui.button>
                    <x-ui.button variant="ghost" @click="stack()">{{ __('Stack Three') }}</x-ui.button>
                </div>

                <div class="rounded-2xl border border-dashed border-border-default bg-surface-subtle p-4 text-xs text-muted">
                    {{ __('These buttons dispatch the real `notify` event handled by the global notification outlet — the same path production code uses. Reach for a notification when feedback follows an action and the user stays on the page; reach for an inline alert (left) when the message is context that should remain on the page.') }}
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Validation and Loading') }}</h2>
                    <p class="text-xs text-muted">{{ __('Error and loading states should be visibly distinct without changing the underlying control structure.') }}</p>
                </div>

                <x-ui.input
                    id="ui-reference-error-input"
                    :label="__('Field Error')"
                    :error="__('This field must remain concise and non-empty.')"
                    :value="__('Too much text for the allowed pattern')"
                />

                <div class="space-y-2">
                    <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Skeleton') }}</div>
                    <div class="space-y-2 rounded-2xl border border-border-default bg-surface-card p-4">
                        <div class="h-4 w-32 animate-pulse rounded bg-surface-subtle"></div>
                        <div class="h-10 w-full animate-pulse rounded-2xl bg-surface-subtle"></div>
                        <div class="h-4 w-3/4 animate-pulse rounded bg-surface-subtle"></div>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Empty State') }}</h2>
                    <p class="text-xs text-muted">{{ __('Empty states should explain what is missing, what the user can do next, and whether action is optional.') }}</p>
                </div>

                <div class="rounded-2xl border border-dashed border-border-default bg-surface-card p-6 text-center">
                    <x-icon name="heroicon-o-document-magnifying-glass" class="mx-auto h-10 w-10 text-muted" />
                    <h3 class="mt-4 text-sm font-medium text-ink">{{ __('No Saved Filters Yet') }}</h3>
                    <p class="mt-2 text-sm text-muted">{{ __('Save a filter when you want to reuse a search configuration across review sessions.') }}</p>
                    <div class="mt-4">
                        <x-ui.button variant="ghost">{{ __('Create Filter') }}</x-ui.button>
                    </div>
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
