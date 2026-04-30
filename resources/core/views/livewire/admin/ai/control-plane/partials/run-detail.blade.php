<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var array<string, mixed> $run */
$controlPlaneContext = request()->only(['from', 'returnTo']);
?>
<div class="space-y-3">
    {{-- Key facts row --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Run ID') }}</span>
            <p class="text-sm text-ink mt-1 font-mono tabular-nums">{{ $run['run_id'] }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Status') }}</span>
            <p class="mt-1">
                @if($run['status_label'])
                    <x-ui.badge :variant="$run['status_color']">
                        {{ $run['status_label'] }}
                    </x-ui.badge>
                @else
                    <x-ui.badge :variant="$run['outcome_color']">
                        {{ $run['outcome_label'] }}
                    </x-ui.badge>
                @endif
            </p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Provider / Model') }}</span>
            <p class="text-sm text-ink mt-1">{{ $run['provider'] }} / {{ $run['model'] }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Source') }}</span>
            <p class="mt-1">
                @if($run['source'] !== '')
                    <x-ui.badge variant="default">{{ $run['source'] }}</x-ui.badge>
                @else
                    <span class="text-sm text-muted">—</span>
                @endif
            </p>
        </div>
    </div>

    {{-- Execution details --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Execution Mode') }}</span>
            <p class="text-sm text-ink mt-1">{{ $run['execution_mode'] !== '' ? ucfirst($run['execution_mode']) : '—' }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Latency') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run['latency_ms'] !== null ? $run['latency_ms'] . ' ms' : '—' }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Timeout Budget') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">
                @if($run['timeout_seconds'] !== null)
                    {{ number_format($run['timeout_seconds']) }}s
                    @if($run['latency_ms'] !== null)
                        <span class="text-muted">(used {{ number_format($run['latency_ms'] / 1000, 1) }}s)</span>
                    @endif
                @else
                    —
                @endif
            </p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Acting For User') }}</span>
            <p class="mt-1 text-sm text-ink">
                @if (($run['acting_for_user_id'] ?? null) === null)
                    <span class="text-muted tabular-nums">—</span>
                @elseif (! empty($run['acting_for_user_name']))
                    <span>{{ $run['acting_for_user_name'] }}</span>
                    <span class="ml-1.5 text-xs font-normal text-muted tabular-nums">#{{ $run['acting_for_user_id'] }}</span>
                @else
                    <span class="tabular-nums">#{{ $run['acting_for_user_id'] }}</span>
                    <span class="ml-1.5 text-xs text-muted">{{ __('Name unavailable') }}</span>
                @endif
            </p>
        </div>
    </div>

    {{-- Tokens and timing --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tokens (prompt)') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run['tokens']['prompt'] ?? '—' }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tokens (completion)') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run['tokens']['completion'] ?? '—' }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Retries') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ $run['retry_attempts'] }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Recorded') }}</span>
            <p class="mt-1">
                <x-ui.datetime :value="! empty($run['recorded_at']) ? $run['recorded_at'] : null" class="text-sm text-ink tabular-nums" />
            </p>
        </div>
    </div>

    {{-- Timestamps and context --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Started At') }}</span>
            <p class="mt-1">
                <x-ui.datetime :value="! empty($run['started_at']) ? $run['started_at'] : null" class="text-sm text-ink tabular-nums" />
            </p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Finished At') }}</span>
            <p class="mt-1">
                <x-ui.datetime :value="! empty($run['finished_at']) ? $run['finished_at'] : null" class="text-sm text-ink tabular-nums" />
            </p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employee') }}</span>
            <p class="mt-1 text-sm text-ink">
                @if (! empty($run['employee_name']))
                    <span>{{ $run['employee_name'] }}</span>
                    <span class="ml-1.5 text-xs font-normal text-muted tabular-nums">#{{ $run['employee_id'] }}</span>
                @else
                    <span class="tabular-nums">#{{ $run['employee_id'] }}</span>
                    <span class="ml-1.5 text-xs text-muted">{{ __('Name unavailable') }}</span>
                @endif
            </p>
        </div>
    </div>

    {{-- Dispatch and session context --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Session') }}</span>
            <p class="text-sm text-ink mt-1 font-mono tabular-nums">{{ $run['session_id'] }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Turn') }}</span>
            <p class="mt-1 text-sm text-ink">
                @if(! empty($run['turn_id']))
                    <a
                        href="{{ route('admin.ai.control-plane', array_merge($controlPlaneContext, ['tab' => 'turns', 'turnId' => $run['turn_id']])) }}"
                        wire:navigate
                        class="font-mono text-xs text-accent hover:underline"
                    >
                        {{ $run['turn_id'] }}
                    </a>
                @else
                    <span class="text-muted">—</span>
                @endif
            </p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Dispatch') }}</span>
            <p class="text-sm text-ink mt-1 font-mono tabular-nums">{{ $run['dispatch_id'] ?? '—' }}</p>
        </div>
    </div>

    {{-- Tool actions --}}
    @if($run['tool_actions'] !== [])
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tool Actions') }}</span>
            <div class="mt-1 flex flex-wrap gap-1.5">
                @foreach($run['tool_actions'] as $action)
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
    @if($run['fallback_attempts'] !== [])
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Fallback Attempts') }}</span>
            <div class="mt-1 space-y-1">
                @foreach($run['fallback_attempts'] as $attempt)
                    <p class="text-xs text-muted">
                        {{ $attempt['provider'] ?? '?' }} / {{ $attempt['model'] ?? '?' }}
                        — <span class="text-danger">{{ $attempt['error'] ?? __('unknown error') }}</span>
                    </p>
                    @if(! empty($attempt['diagnostic']))
                        <p class="text-xs text-muted font-mono ml-4 mt-0.5">{{ $attempt['diagnostic'] }}</p>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- Error details --}}
    @if($run['error_type'] || $run['error_message'])
        <div class="rounded-lg bg-surface-subtle p-3">
            <span class="text-[11px] uppercase tracking-wider font-semibold text-danger">{{ __('Error') }}</span>
            @if($run['error_type'])
                <p class="text-xs text-muted mt-1">{{ __('Type') }}: {{ $run['error_type'] }}</p>
            @endif
            @if($run['error_message'])
                <p class="text-sm text-danger mt-1">{{ $run['error_message'] }}</p>
            @endif
        </div>
    @endif
</div>
