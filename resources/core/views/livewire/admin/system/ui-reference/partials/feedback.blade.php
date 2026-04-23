<div class="space-y-section-gap">
    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Alert Variants') }}</h2>
                    <p class="text-xs text-muted">{!! __('Component: :component', ['component' => '<code>x-ui.alert</code>']) !!}</p>
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
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Action Message') }}</h2>
                    <p class="text-xs text-muted">{!! __('Component: :component', ['component' => '<code>x-ui.action-message</code>']) !!}</p>
                    <p class="text-xs text-muted">{{ __('Action messages are lightweight, transient confirmations that appear near the control that triggered them (for example, beside a Save button).') }}</p>
                </div>

                <div class="flex items-center justify-between gap-3 rounded-2xl border border-border-default bg-surface-card p-4">
                    <x-ui.button size="sm" variant="primary" wire:click="demoActionMessage">
                        {{ __('Trigger “Saved”') }}
                    </x-ui.button>

                    <x-ui.action-message on="ui-reference-action-message" class="text-xs text-status-success" />
                </div>

                <p class="text-xs text-muted">{{ __('Use alerts for durable guidance; use action messages when the feedback is local to a single action.') }}</p>
            </div>
        </x-ui.card>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Banner Feedback') }}</h2>
                    <p class="text-xs text-muted">{!! __('Component: :component', ['component' => '<code>x-ui.banner</code>']) !!}</p>
                    <p class="text-xs text-muted">{{ __('Banners sit in the app chrome (or at the top of a region) to signal ongoing state that should remain visible across navigation until resolved.') }}</p>
                </div>

                <div class="space-y-2">
                    <div class="rounded-2xl border border-dashed border-border-default bg-surface-subtle p-3">
                        <x-ui.banner variant="warning" :title="__('Impersonation Active')" :description="__('You are viewing the system as another user.')">
                            <x-slot name="action">
                                <button type="button" class="text-xs font-medium text-status-warning hover:underline">
                                    {{ __('Stop') }}
                                </button>
                            </x-slot>
                        </x-ui.banner>
                    </div>

                    <p class="text-xs text-muted">{{ __('Use banners sparingly. Prefer inline alerts for page-specific guidance and flash notifications for transient confirmation.') }}</p>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Inline Status') }}</h2>
                    <p class="text-xs text-muted">{!! __('Component: :component', ['component' => '<code>x-ui.inline-status</code>']) !!}</p>
                    <p class="text-xs text-muted">{{ __('Inline status is compact feedback that anchors to a control (validation, connectivity, background checks). It should not look like a full alert box.') }}</p>
                </div>

                <div class="space-y-3">
                    <div class="space-y-1">
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Connectivity') }}</div>
                        <x-ui.inline-status variant="success" :text="__('Connected to provider')" />
                    </div>

                    <div class="space-y-1">
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Progress') }}</div>
                        <x-ui.inline-status variant="info">
                            <x-slot name="icon">
                                <div class="h-3 w-3 animate-spin rounded-full border border-accent border-t-transparent"></div>
                            </x-slot>
                            <span class="text-xs text-muted">{{ __('Checking endpoint…') }}</span>
                        </x-ui.inline-status>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Failure') }}</div>
                        <x-ui.inline-status variant="danger" :text="__('Endpoint unreachable')">
                            <x-slot name="action">
                                <button type="button" class="ml-1 rounded text-xs text-accent hover:underline focus:ring-2 focus:ring-accent focus:ring-offset-1">
                                    {{ __('Retry') }}
                                </button>
                            </x-slot>
                        </x-ui.inline-status>
                    </div>
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Validation and Loading') }}</h2>
                    <p class="text-xs text-muted">{!! __('Component: :component', ['component' => '<code>x-ui.input</code>']) !!}</p>
                    <p class="text-xs text-muted">{{ __('Error and loading states should be visibly distinct without changing the underlying control structure.') }}</p>
                </div>

                <x-ui.input
                    id="ui-reference-error-input"
                    :label="__('Field Error')"
                    :error="__('This field must remain concise and non-empty.')"
                    :value="__('Too much text for the allowed pattern')"
                />

            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Empty State') }}</h2>
                    <p class="text-xs text-muted">{!! __('Component: :component', ['component' => '<code>x-ui.card</code>, <code>x-ui.button</code>']) !!}</p>
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
