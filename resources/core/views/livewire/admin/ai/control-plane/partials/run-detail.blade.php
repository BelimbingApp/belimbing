<?php

use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var RunInspection $run */
?>
<div class="space-y-3">
    {{-- Key facts row --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Run ID') }}</span>
            <p class="text-sm text-ink mt-1 font-mono tabular-nums">{{ $run->runId }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Outcome') }}</span>
            <p class="mt-1">
                <x-ui.badge :variant="$run->outcome === 'success' ? 'success' : ($run->outcome === 'error' ? 'danger' : 'default')">
                    {{ ucfirst($run->outcome) }}
                </x-ui.badge>
            </p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Provider / Model') }}</span>
            <p class="text-sm text-ink mt-1">{{ $run->provider }} / {{ $run->model }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Latency') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run->latencyMs !== null ? $run->latencyMs . ' ms' : '—' }}</p>
        </div>
    </div>

    {{-- Tokens and timing --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tokens (prompt)') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run->tokens['prompt'] ?? '—' }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tokens (completion)') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run->tokens['completion'] ?? '—' }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Retries') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run->retryAttempts }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Recorded') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run->recordedAt }}</p>
        </div>
    </div>

    {{-- Dispatch and session context --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Session') }}</span>
            <p class="text-sm text-ink mt-1 font-mono tabular-nums">{{ $run->sessionId }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Dispatch') }}</span>
            <p class="text-sm text-ink mt-1 font-mono tabular-nums">{{ $run->dispatchId ?? '—' }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employee') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run->employeeId }}</p>
        </div>
    </div>

    {{-- Tool actions --}}
    @if($run->toolActions !== [])
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tool Actions') }}</span>
            <div class="mt-1 flex flex-wrap gap-1.5">
                @foreach($run->toolActions as $action)
                    <x-ui.badge variant="default">
                        {{ $action['tool'] }}
                        @if($action['result_length'] !== null)
                            <span class="text-muted ml-1">({{ number_format($action['result_length']) }} {{ __('chars') }})</span>
                        @endif
                    </x-ui.badge>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Fallback attempts --}}
    @if($run->fallbackAttempts !== [])
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Fallback Attempts') }}</span>
            <div class="mt-1 space-y-1">
                @foreach($run->fallbackAttempts as $attempt)
                    <p class="text-xs text-muted">
                        {{ $attempt['provider'] ?? '?' }} / {{ $attempt['model'] ?? '?' }}
                        — <span class="text-danger">{{ $attempt['error'] ?? __('unknown error') }}</span>
                    </p>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Error details --}}
    @if($run->errorType || $run->errorMessage)
        <div class="rounded-lg bg-surface-subtle p-3">
            <span class="text-[11px] uppercase tracking-wider font-semibold text-danger">{{ __('Error') }}</span>
            @if($run->errorType)
                <p class="text-xs text-muted mt-1">{{ __('Type') }}: {{ $run->errorType }}</p>
            @endif
            @if($run->errorMessage)
                <p class="text-sm text-danger mt-1">{{ $run->errorMessage }}</p>
            @endif
        </div>
    @endif
</div>
