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
                    success: false,
                    warning: false,
                    info: false,
                    timer(name, delay = 4500) {
                        this[name] = true
                        setTimeout(() => this[name] = false, delay)
                    },
                    stack() {
                        this.timer('success', 4200)
                        setTimeout(() => this.timer('warning', 5000), 250)
                        setTimeout(() => this.timer('info', 5600), 500)
                    },
                }"
                class="space-y-4"
            >
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Flash Notification Demo') }}</h2>
                    <p class="text-xs text-muted">{{ __('Flash notifications are transient and stack at the viewport edge. This demo uses the proposed standard: top-right placement, title above supporting copy, and auto-dismiss after a short dwell time.') }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button variant="primary" @click="timer('success')">{{ __('Success Flash') }}</x-ui.button>
                    <x-ui.button variant="secondary" @click="timer('warning', 5200)">{{ __('Warning Flash') }}</x-ui.button>
                    <x-ui.button variant="ghost" @click="stack()">{{ __('Stack Three') }}</x-ui.button>
                </div>

                <div class="rounded-2xl border border-dashed border-border-default bg-surface-subtle p-4 text-xs text-muted">
                    {{ __('Preview behavior: flash notifications appear in the top-right viewport corner, allow stacking, and disappear automatically unless the pattern is later revised for a persistent critical state.') }}
                </div>

                <x-ui.flash-stack>
                    <div x-cloak x-show="success" x-transition.opacity.scale.duration.200ms>
                        <x-ui.flash
                            variant="success"
                            :title="__('Settings Saved')"
                            :description="__('Compact spacing keeps the message readable without overwhelming the page.')"
                        />
                    </div>

                    <div x-cloak x-show="warning" x-transition.opacity.scale.duration.200ms>
                        <x-ui.flash
                            variant="warning"
                            :title="__('Review Needed')"
                            :description="__('A second flash should stack cleanly under the first with the same internal rhythm.')"
                        />
                    </div>

                    <div x-cloak x-show="info" x-transition.opacity.scale.duration.200ms>
                        <x-ui.flash
                            variant="info"
                            :title="__('Background Sync Running')"
                            :description="__('Use transient info for low-friction operational status, not for durable audit history.')"
                        />
                    </div>
                </x-ui.flash-stack>
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
