<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var array<string, mixed> $rail */
$counts = is_array($rail['counts_by_type'] ?? null) ? $rail['counts_by_type'] : [];
$phaseProgression = is_array($rail['phase_progression'] ?? null) ? $rail['phase_progression'] : [];
?>
<section
    aria-label="{{ __('Lifecycle summary') }}"
    class="rounded-2xl border-l-4 border-accent/50 border-y border-r border-border-default bg-surface-subtle/60 px-card-inner py-3"
>
    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-2">
        <h4 class="text-[11px] font-semibold uppercase tracking-wider text-muted">
            {{ __('Lifecycle summary') }}
        </h4>

        @if (! empty($rail['current_status_label']))
            <div class="flex items-baseline gap-1.5">
                <span class="text-[11px] text-muted">{{ __('Status') }}:</span>
                <x-ui.badge :variant="$rail['current_status_color'] ?? 'default'">{{ $rail['current_status_label'] }}</x-ui.badge>
            </div>
        @endif

        @if ($phaseProgression !== [])
            <div class="flex flex-wrap items-baseline gap-1.5 text-xs text-ink">
                <span class="text-[11px] text-muted">{{ __('Phases') }}:</span>
                @foreach ($phaseProgression as $phase)
                    <span class="font-mono">{{ $phase }}</span>
                    @if (! $loop->last)
                        <span class="text-muted">→</span>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    @if ($counts !== [])
        <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px]">
            @foreach ($counts as $type => $count)
                <div class="flex items-baseline gap-1">
                    <dt class="text-muted">{{ $type }}</dt>
                    <dd class="font-mono tabular-nums text-ink">{{ $count }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    <p class="mt-2 text-[10px] text-muted">
        {{ __('Wire entries below are tagged with [META] badges when their timestamp falls inside a meta-event window. This is a per-page hint, not a strict full-log merge.') }}
    </p>
</section>
