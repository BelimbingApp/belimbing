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
            <li class="flex flex-wrap items-baseline gap-2 text-xs">
                <span class="inline-flex items-center rounded bg-surface-subtle px-1 py-px text-[9px] font-bold uppercase tracking-wider text-muted ring-1 ring-border-default">
                    {{ __('META') }}
                </span>
                @if (isset($milestone['seq']))
                    <span class="font-mono text-[11px] text-muted">#{{ $milestone['seq'] }}</span>
                @endif
                <x-ui.badge :variant="$milestone['severity'] ?? 'info'">{{ $milestone['label'] ?? $milestone['type'] ?? '' }}</x-ui.badge>
                @if (! empty($milestone['has_gap_warning']))
                    <x-ui.badge variant="warning">
                        {{ __('Gap :sec s', ['sec' => number_format(($milestone['gap_ms'] ?? 0) / 1000, 1)]) }}
                    </x-ui.badge>
                @endif
                @if (! empty($milestone['summary']))
                    <span class="text-ink">{{ $milestone['summary'] }}</span>
                @endif
                <span class="ml-auto shrink-0 text-muted tabular-nums">
                    <x-ui.datetime :value="$milestone['at'] ?? null" class="tabular-nums" />
                </span>
            </li>
        @endforeach
    </ol>
</section>
