<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var list<array<string, mixed>> $entries */
?>
<div class="space-y-3">
    @if (! $wireLoggingEnabled)
        <x-ui.alert variant="info">
            {{ __('Wire logging is disabled. Enable AI wire logging to capture raw transport requests, responses, and full tool payloads for future runs.') }}
        </x-ui.alert>
    @elseif ($entries === [])
        <x-ui.alert variant="info">
            {{ __('No wire log entries were retained for this run.') }}
        </x-ui.alert>
    @else
        @foreach ($entries as $index => $entry)
            @php
                $payload = $entry;
                unset($payload['at'], $payload['type']);
            @endphp
            <details class="rounded-2xl border border-border-default bg-surface-card p-card-inner" @if ($index === 0) open @endif>
                <summary class="flex cursor-pointer flex-col gap-1 text-sm text-ink sm:flex-row sm:items-center sm:justify-between">
                    <span class="font-medium">{{ $entry['type'] ?? __('Unknown entry') }}</span>
                    <span class="text-xs text-muted tabular-nums">{{ $entry['at'] ?? '---' }}</span>
                </summary>
                <pre class="mt-3 overflow-x-auto rounded-2xl bg-surface-subtle p-3 text-[11px] text-muted">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @endforeach
    @endif
</div>
