<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var array{overview: array<string, int>, entries: list<array<string, mixed>>} $readable */
/** @var string $runId */
?>

<div class="space-y-3">
    <section class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
        <h3 class="text-sm font-medium text-ink">{{ __('Transport Overview') }}</h3>

        @if (($readable['overview'] ?? []) === [])
            <p class="mt-2 text-sm text-muted">{{ __('No transport entries in this window.') }}</p>
        @else
            <dl class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($readable['overview'] as $type => $count)
                    <div class="rounded-xl bg-surface-subtle p-3">
                        <dt class="truncate text-xs text-muted">{{ $type }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-ink tabular-nums">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>
</div>
