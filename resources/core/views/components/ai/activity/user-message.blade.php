<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'content',
    'timestamp',
    'meta' => [],
])

<div class="flex justify-end">
    <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm bg-accent text-accent-on">
        <div class="whitespace-pre-wrap wrap-break-word">{{ $content }}</div>
        @php
            /** @var list<array<string, mixed>> $attachments */
            $attachments = is_array($meta['attachments_ui'] ?? null) ? $meta['attachments_ui'] : [];
        @endphp
        @if ($attachments !== [])
            <div class="mt-2 pt-2 border-t border-white/15">
                <x-ai.activity.attachments :attachments="$attachments" />
            </div>
        @endif
        <x-ai.message-meta :timestamp="$timestamp" tone="inverse" />
    </div>
</div>
