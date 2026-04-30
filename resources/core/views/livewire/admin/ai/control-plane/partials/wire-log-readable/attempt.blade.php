<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var array<string, mixed> $attempt */
/** @var string $runId */
/** @var bool $showAttemptHeader */
$replay = $attempt['replay'] ?? null;
$usageChip = $attempt['usage_chip'] ?? null;
$formatCount = static fn (?int $value): string => $value !== null ? number_format($value) : '—';
$formatCents = static fn (?int $cents): string => $cents !== null
    ? '$'.number_format($cents / 100, 2)
    : '—';
?>
<div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
    @if ($showAttemptHeader)
        <div class="mb-3 flex flex-wrap items-center gap-2 border-b border-border-default/60 pb-3 text-sm">
            <x-ui.badge :variant="$attempt['outcome_severity'] ?? 'default'">
                {{ __('Attempt :index', ['index' => $attempt['index']]) }}
            </x-ui.badge>
            <span class="text-ink">{{ $attempt['summary'] }}</span>
        </div>
    @endif

    @if (is_array($usageChip))
        <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-border-default/60 bg-surface-subtle/40 px-3 py-2 text-xs">
            <x-ui.badge variant="info">{{ __('Usage') }}</x-ui.badge>
            <span class="text-muted">{{ __('In') }} <span class="font-mono text-ink">{{ $formatCount($usageChip['prompt_tokens'] ?? null) }}</span></span>
            <span class="text-muted">{{ __('Cached') }} <span class="font-mono text-ink">{{ $formatCount($usageChip['cached_input_tokens'] ?? null) }}</span></span>
            <span class="text-muted">{{ __('Out') }} <span class="font-mono text-ink">{{ $formatCount($usageChip['completion_tokens'] ?? null) }}</span></span>
            <span class="text-muted">{{ __('Reasoning') }} <span class="font-mono text-ink">{{ $formatCount($usageChip['reasoning_tokens'] ?? null) }}</span></span>
            <span class="text-muted">{{ __('Total') }} <span class="font-mono text-ink">{{ $formatCount($usageChip['total_tokens'] ?? null) }}</span></span>
            <span class="text-muted">{{ __('Cost') }} <span class="font-mono text-ink">{{ $formatCents($usageChip['cost_total_cents'] ?? null) }}</span></span>
            @if (! empty($usageChip['pricing_source']))
                <x-ui.badge variant="default">{{ $usageChip['pricing_source'] }}</x-ui.badge>
            @endif
            @if (! empty($usageChip['finish_reason']))
                <x-ui.badge variant="success">{{ __('finish=:reason', ['reason' => $usageChip['finish_reason']]) }}</x-ui.badge>
            @endif
        </div>
    @endif

    @if ($replay)
        <details class="mb-3 rounded-lg border border-border-default/60 bg-surface-subtle/40">
            <summary class="cursor-pointer px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted">
                {{ __('Copy as cURL') }}
                <span class="ml-1 font-normal lowercase text-muted/80">
                    ({{ \Illuminate\Support\Number::fileSize($replay['body_byte_count'] ?? 0) }} {{ __('body') }})
                </span>
            </summary>
            <div x-data="{ copied: false }" class="relative px-3 pb-3">
                <button
                    type="button"
                    @click="navigator.clipboard.writeText($refs.curl.textContent.trim()); copied = true; setTimeout(() => copied = false, 1500);"
                    class="absolute right-5 top-1 inline-flex items-center gap-1 rounded-md border border-border-default bg-surface-card px-1.5 py-0.5 text-[11px] text-ink shadow-sm hover:bg-surface-subtle"
                >
                    <x-icon name="heroicon-o-clipboard-document-list" class="size-3" />
                    <span x-show="!copied">{{ __('Copy') }}</span>
                    <span x-show="copied" x-cloak class="text-status-success">{{ __('Copied!') }}</span>
                </button>
                <pre x-ref="curl" class="max-h-56 overflow-auto rounded bg-surface-card p-2 pr-16 text-[11px] text-ink">{{ $replay['curl'] }}</pre>
            </div>
        </details>
    @endif

    <div class="space-y-3">
        @foreach ($attempt['sections'] as $section)
            @if ($section['kind'] === 'event')
                @include('livewire.admin.ai.control-plane.partials.wire-log-readable.event', [
                    'event' => $section,
                    'runId' => $runId,
                ])
            @elseif ($section['kind'] === 'stream_block')
                @include('livewire.admin.ai.control-plane.partials.wire-log-readable.stream-block', [
                    'block' => $section,
                    'runId' => $runId,
                ])
            @endif
        @endforeach
    </div>
</div>
