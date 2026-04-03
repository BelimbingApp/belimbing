<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'content',
    'timestamp',
    'runId' => null,
    'provider' => null,
    'model' => null,
    'markdown',
    'tokens' => null,
    'latencyMs' => null,
    'timeoutSeconds' => null,
    'retryAttempts' => null,
    'fallbackAttempts' => null,
    'runStatus' => null,
])

<div class="flex justify-start">
    <div class="max-w-[90%] text-sm text-ink">
        <div class="agent-prose max-w-full overflow-x-auto">{!! $markdown->render($content) !!}</div>
        <x-ai.message-meta
            :timestamp="$timestamp"
            :provider="$provider"
            :model="$model"
            :run-id="$runId"
            :tokens="$tokens"
            :latency-ms="$latencyMs"
            :timeout-seconds="$timeoutSeconds"
            :retry-attempts="$retryAttempts"
            :fallback-attempts="$fallbackAttempts"
            :run-status="$runStatus"
        />
    </div>
</div>
