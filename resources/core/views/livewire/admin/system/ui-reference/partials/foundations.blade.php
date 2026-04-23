<div class="space-y-section-gap">
    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Color Roles')"
                    component="<code>tokens.css</code>"
                >
                    {{ __('Views should use semantic roles rather than raw palette classes. This page shows the current implemented roles from `tokens.css`.') }}
                </x-ui.catalog-section>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($colorTokens as $token)
                        <div class="rounded-2xl border border-border-default p-3">
                            <div class="mb-3 h-16 rounded-2xl border {{ $token['class'] }}"></div>
                            <div class="text-sm font-medium text-ink">{{ __($token['name']) }}</div>
                            <div class="text-xs text-muted">{{ __($token['note']) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Typography Roles')"
                    component="<code>tokens.css</code>"
                >
                    {{ __('The system should read as compact and calm. The examples below show the intended hierarchy and tone.') }}
                </x-ui.catalog-section>

                <div class="space-y-4">
                    @foreach ($typeSamples as $sample)
                        <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                            <div class="mb-2 text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __($sample['label']) }}</div>
                            <div class="{{ $sample['class'] }}">{{ __($sample['sample']) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Spacing Rhythm')"
                    component="<code>tokens.css</code>"
                >
                    {{ __('Density is controlled by semantic spacing tokens. Blade should reference the role, not hardcoded utility spacing for standard controls.') }}
                </x-ui.catalog-section>

                <div class="space-y-3">
                    @foreach ($spacingTokens as $token)
                        <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <div class="text-sm font-medium text-ink">{{ __($token['token']) }}</div>
                                <div class="text-xs text-muted">{{ __($token['note']) }}</div>
                            </div>
                            <div class="rounded-xl bg-surface-subtle">
                                <div class="{{ $token['class'] }}">
                                    <div class="rounded-lg border border-dashed border-border-input bg-surface-card px-3 py-2 text-xs text-muted">
                                        {{ __('Reference spacing preview') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Shape, Elevation, and Icons')"
                    component="<code>x-ui.card</code>, <code>x-icon</code>"
                >
                    {{ __('Cards and controls should separate themselves through radius, border, and restrained shadow. Icons should favor outline variants except in dense inline contexts.') }}
                </x-ui.catalog-section>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner shadow-sm">
                        <div class="text-sm font-medium text-ink">{{ __('Card Surface') }}</div>
                        <p class="mt-1 text-xs text-muted">{{ __('Border plus light shadow for ordinary grouped content.') }}</p>
                    </div>
                    <div class="rounded-2xl border border-border-default bg-surface-card p-4 shadow-xl">
                        <div class="text-sm font-medium text-ink">{{ __('Overlay Surface') }}</div>
                        <p class="mt-1 text-xs text-muted">{{ __('A stronger elevation level reserved for overlays and interruption.') }}</p>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($iconSamples as $icon)
                        <div class="flex items-center gap-3 rounded-2xl border border-border-default bg-surface-card p-4">
                            <x-icon :name="$icon['name']" class="h-5 w-5 text-accent" />
                            <div>
                                <div class="text-sm font-medium text-ink">{{ $icon['name'] }}</div>
                                <p class="text-xs text-muted">{{ __($icon['usage']) }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>
    </div>
</div>

