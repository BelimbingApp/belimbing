@props([
    'providerName' => '',
    'help' => null,
    'colspan' => 7,
    'rowAttributes' => null,
])

@php
    $rowAttributes = $rowAttributes instanceof \Illuminate\View\ComponentAttributeBag
        ? $rowAttributes
        : new \Illuminate\View\ComponentAttributeBag();
@endphp

@if($help)
    <tr {!! $rowAttributes !!}>
        <td colspan="{{ $colspan }}" class="px-4 pb-3 pt-1">
            <div
                x-data
                x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'nearest' })"
                class="mt-2 rounded-2xl border border-border-default bg-surface-card p-4 text-sm text-muted shadow-lg shadow-black/[0.02]"
            >
                <div class="flex items-start justify-between gap-3 mb-3">
                    <div class="flex items-center gap-1.5">
                        <x-icon name="heroicon-o-question-mark-circle" class="w-4 h-4 text-accent shrink-0" />
                        <span class="text-[10px] font-bold uppercase tracking-wider text-accent">{{ __('Setup & Troubleshooting') }}</span>
                        <span class="text-xs text-muted/70">— {{ $providerName }}</span>
                    </div>
                    <button
                        type="button"
                        wire:click.stop="closeProviderHelp"
                        class="text-muted hover:text-ink p-0.5 rounded hover:bg-surface-subtle shrink-0"
                        aria-label="{{ __('Close') }}"
                        title="{{ __('Close') }}"
                    >
                        <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                    </button>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    @if(!empty($help['setup_steps']))
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted mb-1.5">{{ __('How to set up') }}</p>
                            <ol class="space-y-1 list-decimal list-outside pl-4">
                                @foreach($help['setup_steps'] as $step)
                                    <li class="leading-relaxed">{{ $step }}</li>
                                @endforeach
                            </ol>
                        </div>
                    @endif

                    @if(!empty($help['troubleshooting_tips']))
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted mb-1.5">{{ __('Troubleshooting') }}</p>
                            <ul class="space-y-1 list-disc list-outside pl-4">
                                @foreach($help['troubleshooting_tips'] as $tip)
                                    <li class="leading-relaxed">{{ $tip }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                @if($help['documentation_url'])
                    <div class="mt-3 pt-3 border-t border-border-default">
                        <a
                            href="{{ $help['documentation_url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-xs text-accent hover:underline"
                        >
                            <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                            {{ __('Official documentation') }}
                        </a>
                    </div>
                @endif
            </div>
        </td>
    </tr>
@endif
