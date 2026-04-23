@props([
    'mode' => 'card',
])

@if ($mode === 'rail')
    <div class="border-b border-border-default px-2 py-1.5">
        <span class="text-[11px] uppercase tracking-wider font-semibold text-muted select-none">{{ __('Catalog Pages') }}</span>
    </div>

    <div class="px-1.5 py-1.5 border-b border-border-default bg-surface-pinned">
        <p class="text-xs text-muted">
            {{ __('Use the rail as the standard index of UI reference groups. Rich descriptions stay visible so the navigator remains useful during feature ideation, not just page switching.') }}
        </p>
    </div>

    <nav
        aria-label="{{ __('UI reference sections') }}"
        class="flex-1 overflow-y-auto px-2 pb-2 pt-2"
    >
        @foreach ($sections as $sectionOption)
            <a
                href="{{ $this->sectionUrl($sectionOption) }}"
                wire:navigate
                @class([
                    'block rounded-2xl px-3 py-3 transition-colors',
                    'bg-accent/10 text-ink' => $currentSection === $sectionOption,
                    'text-ink hover:bg-surface-subtle' => $currentSection !== $sectionOption,
                ])
                @if ($currentSection === $sectionOption) aria-current="page" data-active @endif
            >
                <div class="flex items-start gap-3">
                    <div @class([
                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-2xl',
                        'bg-surface-card text-accent shadow-sm border border-border-default' => $currentSection === $sectionOption,
                        'bg-surface-card/70 text-accent' => $currentSection !== $sectionOption,
                    ])>
                        <x-icon :name="$sectionOption->icon()" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <div @class([
                            'text-sm font-medium',
                            'text-ink' => true,
                        ])>{{ __($sectionOption->label()) }}</div>
                        <p class="mt-1 text-xs text-muted">{{ __($sectionOption->description()) }}</p>
                    </div>
                </div>
            </a>
        @endforeach
    </nav>
@else
    <x-ui.card>
        <div class="space-y-3">
            <div class="flex flex-col gap-1 md:flex-row md:items-end md:justify-between">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Catalog Pages') }}</h2>
                    <p class="text-xs text-muted">{{ __('Each page has one job. Use the group that matches the design question you are trying to answer.') }}</p>
                </div>
                <x-ui.badge variant="accent">{{ $currentSection->label() }}</x-ui.badge>
            </div>

            <nav aria-label="{{ __('UI reference sections') }}" class="grid gap-2 md:grid-cols-2">
                @foreach ($sections as $sectionOption)
                    <a
                        href="{{ $this->sectionUrl($sectionOption) }}"
                        wire:navigate
                        @class([
                            'rounded-2xl border px-4 py-3 transition-colors',
                            'border-accent bg-accent/10 text-ink' => $currentSection === $sectionOption,
                            'border-border-default bg-surface-card text-ink hover:bg-surface-subtle' => $currentSection !== $sectionOption,
                        ])
                    >
                        <div class="flex items-start gap-3">
                            <x-icon :name="$sectionOption->icon()" class="mt-0.5 h-5 w-5 shrink-0 text-accent" />
                            <div class="min-w-0">
                                <div class="text-sm font-medium">{{ __($sectionOption->label()) }}</div>
                                <p class="mt-1 text-xs text-muted">{{ __($sectionOption->description()) }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </nav>
        </div>
    </x-ui.card>
@endif
