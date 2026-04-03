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
        <div class="whitespace-pre-wrap break-words">{{ $content }}</div>
        <x-ai.message-meta :timestamp="$timestamp" tone="inverse" />
    </div>
</div>
