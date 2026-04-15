<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'message',
    'errorType' => null,
    'timestamp',
    'runId' => null,
    'provider' => null,
    'model' => null,
    'markdown',
    'fallbackAttempts' => null,
    'errorMessage' => null,
    'latencyMs' => null,
])

<div class="flex justify-start">
    <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm bg-red-500/10 text-ink border border-red-500/20">
        <div class="flex items-center gap-1.5 mb-0.5">
            <x-icon name="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5 text-red-500" />
            <span class="text-[10px] font-semibold uppercase tracking-wider text-red-500">{{ __('Error') }}</span>
            @if ($errorType)
                <span class="text-[10px] text-red-400">{{ $errorType }}</span>
            @endif
        </div>
        <div class="agent-prose max-w-full overflow-x-auto">{!! $markdown->render($message) !!}</div>
        <x-ai.message-meta
            :timestamp="$timestamp"
            :provider="$provider"
            :model="$model"
            :run-id="$runId"
            :latency-ms="$latencyMs"
            :fallback-attempts="$fallbackAttempts"
            :error-type="$errorType"
            :error-message="$errorMessage"
        />
    </div>
</div>
