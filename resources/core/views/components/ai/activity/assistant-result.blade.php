@props([
    'content',
    'timestamp',
    'runId' => null,
    'provider' => null,
    'model' => null,
    'markdown',
    'tokens' => null,
    'latencyMs' => null,
    'aiActiveDurationMs' => null,
    'timeoutSeconds' => null,
    'retryAttempts' => null,
    'runStatus' => null,
    'stopNote' => null,
])

<div class="flex justify-start">
    <div class="max-w-[90%] text-sm text-ink">
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
            :aiActiveDurationMs="$aiActiveDurationMs"
            :timeoutSeconds="$timeoutSeconds"
            :retryAttempts="$retryAttempts"
            :runStatus="$runStatus"
        />
    </div>
</div>
