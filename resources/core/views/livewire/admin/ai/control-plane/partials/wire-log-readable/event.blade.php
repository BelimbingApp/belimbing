<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var array<string, mixed> $event */
/** @var string $runId */
$entryNumber = $event['entry_number'] ?? 0;
$previewStatus = $event['preview_status'] ?? 'full';
$isOversized = $previewStatus === 'line_omitted';
$payloadPretty = $event['payload_pretty'] ?? '';
?>
<details
    id="wire-log-entry-{{ $entryNumber }}"
    class="rounded-xl border border-border-default/60 bg-surface-subtle/40"
>
    <summary class="flex cursor-pointer flex-wrap items-start justify-between gap-2 p-3 text-sm text-ink">
        <div class="flex min-w-0 flex-wrap items-center gap-2">
            <x-ui.badge :variant="$event['severity'] ?? 'default'">{{ $event['label'] }}</x-ui.badge>
            <span class="font-mono text-[11px] text-muted">#{{ $entryNumber }}</span>
            <span class="truncate text-ink">{{ $event['summary'] }}</span>
        </div>
        <x-ui.datetime :value="$event['at'] ?? null" class="shrink-0 text-xs text-muted tabular-nums" />
    </summary>

    @php($details = $event['details'] ?? [])
    @if (! empty($details))
        <dl class="mx-3 grid gap-x-3 gap-y-1 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($details as $key => $value)
                @if ($value !== null && $value !== '' && ! is_array($value))
                    <div class="flex min-w-0 items-baseline gap-1.5">
                        <dt class="shrink-0 text-muted">{{ str_replace('_', ' ', $key) }}:</dt>
                        <dd class="truncate font-mono text-ink">
                            @if (is_bool($value))
                                {{ $value ? 'true' : 'false' }}
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endif
            @endforeach
        </dl>
    @endif

    @if ($isOversized)
        <div class="m-3 flex flex-wrap items-center gap-2 rounded-lg border border-status-warning-subtle/40 bg-status-warning-subtle/20 p-2 text-[11px] text-status-warning">
            <span>{{ $payloadPretty !== '' ? $payloadPretty : __('Payload preview omitted because this wire-log entry is oversized.') }}</span>
            <a
                href="{{ route('admin.ai.runs.wire-log-entry', ['runId' => $runId, 'entryNumber' => $entryNumber]) }}"
                target="_blank"
                rel="noreferrer"
                class="ml-auto inline-flex items-center gap-1 rounded-md border border-border-default bg-surface-subtle px-2 py-0.5 text-ink hover:bg-surface-card"
            >
                <x-icon name="heroicon-o-arrow-top-right-on-square" class="size-3" />
                {{ __('Open raw entry') }}
            </a>
        </div>
    @else
        <pre class="mx-3 mb-3 overflow-x-auto rounded-lg bg-surface-card p-2 text-[11px] text-muted">{{ $payloadPretty !== '' ? $payloadPretty : '{}' }}</pre>
    @endif
</details>
