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
    'stopNote' => null,
])

<div class="flex justify-start">
    <div class="max-w-[90%] text-sm text-ink">
        @if (is_array($fallbackAttempts) && count($fallbackAttempts) > 0)
            @php
                $lastAttempt = end($fallbackAttempts);
                $failedProvider = $lastAttempt['provider'] ?? '?';
                $failedModel = $lastAttempt['model'] ?? '?';
                $failedError = $lastAttempt['error'] ?? __('unknown error');
            @endphp
            <div class="mb-2 flex items-start gap-1.5 rounded-lg bg-amber-500/10 border border-amber-500/20 px-2.5 py-1.5 text-xs text-amber-700 dark:text-amber-400">
                <x-icon name="heroicon-o-arrow-path" class="w-3.5 h-3.5 shrink-0 mt-0.5" />
                <span>
                    @if ($provider !== null && $provider !== '' && $provider !== 'unknown')
                        {{ $failedError }} {{ __('Switched to :provider/:model.', ['provider' => $provider, 'model' => $model]) }}
                    @else
                        {{ $failedError }} {{ __('Backup provider failed.') }}
                    @endif
                </span>
            </div>
        @endif
        @if (is_string($stopNote) && $stopNote !== '')
            <div class="mb-2 flex items-start gap-1.5 rounded-lg border border-amber-500/20 bg-amber-500/10 px-2.5 py-1.5 text-xs text-amber-700 dark:text-amber-400">
                <x-icon name="heroicon-o-stop" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>{{ $stopNote }}</span>
            </div>
        @endif
        @if ($content !== '')
            <div class="agent-prose max-w-full overflow-x-auto">{!! $markdown->render($content) !!}</div>
        @endif
        <x-ai.message-meta
            :timestamp="$timestamp"
            :provider="$provider"
            :model="$model"
            :runId="$runId"
            :tokens="$tokens"
            :latencyMs="$latencyMs"
            :timeoutSeconds="$timeoutSeconds"
            :retryAttempts="$retryAttempts"
            :fallbackAttempts="$fallbackAttempts"
            :runStatus="$runStatus"
        />
    </div>
</div>
