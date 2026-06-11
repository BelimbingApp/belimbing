<?php
/** @var list<array<string, mixed>> $milestones */
?>
<section
    aria-label="{{ __('Lifecycle milestones') }}"
    class="rounded-2xl border-l-4 border-accent/50 border-y border-r border-border-default bg-surface-subtle/60 px-card-inner py-card-inner"
>
    <header class="mb-3 flex items-baseline justify-between gap-3">
        <h4 class="text-[11px] font-semibold uppercase tracking-wider text-muted">
            {{ __('Lifecycle milestones') }}
        </h4>
        <span class="text-[11px] text-muted tabular-nums">
            {{ trans_choice('{0} :count milestone|{1} :count milestone|[2,*] :count milestones', count($milestones), ['count' => count($milestones)]) }}
        </span>
    </header>

    <ol class="space-y-1.5">
        @foreach ($milestones as $milestone)
            @php($entryNumber = isset($milestone['seq']) ? (int) $milestone['seq'] : null)
            <li class="flex flex-wrap items-baseline gap-2 text-xs">
                @if ($entryNumber !== null && $entryNumber > 0)
                    <button
                        type="button"
                        wire:click="focusWireLogEntry({{ $entryNumber }})"
                        class="group inline-flex min-w-0 flex-wrap items-baseline gap-2 rounded-md text-left outline-none transition-colors hover:bg-surface-card hover:ring-1 hover:ring-border-default focus-visible:ring-2 focus-visible:ring-accent/40"
                        title="{{ __('Open wire-log entry #:number', ['number' => $entryNumber]) }}"
                    >
                        <span class="inline-flex items-center rounded bg-surface-subtle px-1 py-px text-[9px] font-bold uppercase tracking-wider text-muted ring-1 ring-border-default group-hover:text-accent">
                            {{ __('META') }}
                        </span>
                        <span class="font-mono text-[11px] text-muted group-hover:text-accent">#{{ $entryNumber }}</span>
                        <x-ui.badge :variant="$milestone['severity'] ?? 'info'">{{ $milestone['label'] ?? $milestone['type'] ?? '' }}</x-ui.badge>
                        @if (! empty($milestone['has_gap_warning']))
                            <x-ui.badge variant="warning">
                                {{ __('Gap :sec s', ['sec' => number_format(($milestone['gap_ms'] ?? 0) / 1000, 1)]) }}
                            </x-ui.badge>
                        @endif
                        @if (! empty($milestone['summary']))
                            <span class="text-ink group-hover:text-accent">{{ $milestone['summary'] }}</span>
                        @endif
                    </button>
                @else
                    <span class="inline-flex items-center rounded bg-surface-subtle px-1 py-px text-[9px] font-bold uppercase tracking-wider text-muted ring-1 ring-border-default">
                        {{ __('META') }}
                    </span>
                    <x-ui.badge :variant="$milestone['severity'] ?? 'info'">{{ $milestone['label'] ?? $milestone['type'] ?? '' }}</x-ui.badge>
                    @if (! empty($milestone['summary']))
                        <span class="text-ink">{{ $milestone['summary'] }}</span>
                    @endif
                @endif
                <span class="ml-auto shrink-0 text-muted tabular-nums">
                    <x-ui.datetime :value="$milestone['at'] ?? null" class="tabular-nums" />
                </span>
            </li>
        @endforeach
    </ol>
</section>
