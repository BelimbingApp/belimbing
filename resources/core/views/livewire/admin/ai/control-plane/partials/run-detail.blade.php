<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var array<string, mixed> $run */
$controlPlaneContext = request()->only(['from', 'returnTo']);
$formatCents = static fn (?int $cents): string => $cents !== null
    ? '$'.number_format($cents / 100, 2)
    : '—';
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
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tokens (prompt)') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">
                {{ isset($run['tokens']['prompt']) ? number_format((int) $run['tokens']['prompt']) : '—' }}
                @if(! empty($run['tokens']['cached_input']))
                    <span class="text-muted text-xs">({{ __(':n cached', ['n' => number_format((int) $run['tokens']['cached_input'])]) }})</span>
                @endif
            </p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tokens (completion)') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">
                {{ isset($run['tokens']['completion']) ? number_format((int) $run['tokens']['completion']) : '—' }}
                @if(! empty($run['tokens']['reasoning']))
                    <span class="text-muted text-xs">({{ __(':n reasoning', ['n' => number_format((int) $run['tokens']['reasoning'])]) }})</span>
                @endif
            </p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tokens (total)') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ isset($run['tokens']['total']) ? number_format((int) $run['tokens']['total']) : '—' }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('LLM Calls') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">{{ number_format((int) ($run['call_count'] ?? 0)) }}</p>
        </div>
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Cost') }}</span>
            <p class="text-sm text-ink mt-1 tabular-nums">
                {{ $formatCents(isset($run['cost_total_cents']) ? (int) $run['cost_total_cents'] : null) }}
                @if(! empty($run['pricing_version']))
                    <span class="text-muted text-xs">{{ $run['pricing_version'] }}</span>
                @endif
            </p>
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

    {{-- Per-call usage table --}}
    @if(! empty($run['calls']))
        <div>
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Calls') }}</span>
            <div class="mt-1 overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead class="bg-surface-subtle/80 text-muted uppercase tracking-wider">
                        <tr>
                            <th class="text-left py-table-cell-y px-table-cell-x font-semibold">#</th>
                            <th class="text-left py-table-cell-y px-table-cell-x font-semibold">{{ __('Model') }}</th>
                            <th class="text-right py-table-cell-y px-table-cell-x font-semibold">{{ __('Prompt') }}</th>
                            <th class="text-right py-table-cell-y px-table-cell-x font-semibold">{{ __('Cached') }}</th>
                            <th class="text-right py-table-cell-y px-table-cell-x font-semibold">{{ __('Completion') }}</th>
                            <th class="text-right py-table-cell-y px-table-cell-x font-semibold">{{ __('Reasoning') }}</th>
                            <th class="text-right py-table-cell-y px-table-cell-x font-semibold">{{ __('Total') }}</th>
                            <th class="text-right py-table-cell-y px-table-cell-x font-semibold">{{ __('Cost') }}</th>
                            <th class="text-left py-table-cell-y px-table-cell-x font-semibold">{{ __('Pricing') }}</th>
                            <th class="text-right py-table-cell-y px-table-cell-x font-semibold">{{ __('Latency') }}</th>
                            <th class="text-left py-table-cell-y px-table-cell-x font-semibold">{{ __('Finish') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default">
                        @foreach($run['calls'] as $call)
                            <tr>
                                <td class="py-table-cell-y px-table-cell-x tabular-nums text-ink">{{ (int) $call['attempt_index'] + 1 }}</td>
                                <td class="py-table-cell-y px-table-cell-x text-ink">{{ $call['model'] ?? '—' }}</td>
                                <td class="py-table-cell-y px-table-cell-x tabular-nums text-right text-ink">{{ $call['prompt_tokens'] !== null ? number_format((int) $call['prompt_tokens']) : '—' }}</td>
                                <td class="py-table-cell-y px-table-cell-x tabular-nums text-right text-muted">{{ $call['cached_input_tokens'] !== null ? number_format((int) $call['cached_input_tokens']) : '—' }}</td>
                                <td class="py-table-cell-y px-table-cell-x tabular-nums text-right text-ink">{{ $call['completion_tokens'] !== null ? number_format((int) $call['completion_tokens']) : '—' }}</td>
                                <td class="py-table-cell-y px-table-cell-x tabular-nums text-right text-muted">{{ $call['reasoning_tokens'] !== null ? number_format((int) $call['reasoning_tokens']) : '—' }}</td>
                                <td class="py-table-cell-y px-table-cell-x tabular-nums text-right text-ink">{{ $call['total_tokens'] !== null ? number_format((int) $call['total_tokens']) : '—' }}</td>
                                <td class="py-table-cell-y px-table-cell-x tabular-nums text-right text-ink">{{ $formatCents($call['cost_total_cents'] !== null ? (int) $call['cost_total_cents'] : null) }}</td>
                                <td class="py-table-cell-y px-table-cell-x text-muted">
                                    @if(! empty($call['pricing_source']))
                                        <x-ui.badge variant="default">{{ $call['pricing_source'] }}</x-ui.badge>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-table-cell-y px-table-cell-x tabular-nums text-right text-muted">{{ $call['latency_ms'] !== null ? number_format((int) $call['latency_ms']) . ' ms' : '—' }}</td>
                                <td class="py-table-cell-y px-table-cell-x text-muted">{{ $call['finish_reason'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

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
