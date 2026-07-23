@php
    $statusVariant = fn (string $s): string => match ($s) {
        'succeeded' => 'success',
        'failed' => 'danger',
        'indeterminate' => 'warning',
        default => 'info',
    };

    $fmtDuration = function (?int $ms): string {
        if ($ms === null) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms.'ms';
        }
        $seconds = $ms / 1000;
        if ($seconds < 60) {
            return rtrim(rtrim(number_format($seconds, 1), '0'), '.').'s';
        }
        return intdiv((int) $seconds, 60).'m '.((int) $seconds % 60).'s';
    };

    $effects = function ($summary): string {
        $parts = [];
        foreach ([
            'rows_written' => 'written',
            'rows_inserted' => 'ins',
            'rows_updated' => 'upd',
            'rows_deleted' => 'del',
            'rows_unchanged' => 'unch',
            'rows_rejected' => 'rej',
        ] as $column => $label) {
            if ($summary->{$column} !== null) {
                $parts[] = number_format($summary->{$column}).' '.$label;
            }
        }
        return $parts === [] ? '—' : implode(' · ', $parts);
    };
@endphp

<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-ink">{{ __('Data Operations') }}</h1>
        <p class="text-sm text-muted">{{ __('Every mass data change — Data Share mirror push, force-push and pull, plus AX/IBP imports — from the shared operation ledger, newest first. This is the authoritative history; the audit timeline links here. Rows are written by the operations themselves and are never edited.') }}</p>
    </header>

    <x-ui.session-flash />

    <div class="flex flex-wrap items-end gap-4">
        <label class="flex flex-col gap-1 text-xs text-muted">
            <span class="uppercase tracking-wide">{{ __('Operation') }}</span>
            <select wire:model.live="type" class="rounded border-border-default bg-surface text-sm text-ink">
                <option value="all">{{ __('All operations') }}</option>
                <option value="mirror">{{ __('Mirror') }}</option>
                <option value="import">{{ __('Imports') }}</option>
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs text-muted">
            <span class="uppercase tracking-wide">{{ __('Status') }}</span>
            <select wire:model.live="status" class="rounded border-border-default bg-surface text-sm text-ink">
                <option value="all">{{ __('Any status') }}</option>
                <option value="succeeded">{{ __('Succeeded') }}</option>
                <option value="failed">{{ __('Failed') }}</option>
                <option value="indeterminate">{{ __('Indeterminate') }}</option>
                <option value="running">{{ __('Running') }}</option>
            </select>
        </label>
        <span class="ml-auto text-xs text-muted">{{ __(':n run(s)', ['n' => $runs->total()]) }}</span>
    </div>

    <x-ui.card>
        @if ($runs->isEmpty())
            <p class="py-8 text-center text-sm text-muted">{{ __('No data operations recorded yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-default text-left text-xs uppercase tracking-wide text-muted">
                            <th class="px-3 py-2 font-medium">{{ __('Run') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Type') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Actor') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Source / endpoint') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('Tables') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('Rows') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Result') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('Duration') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('When') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr
                                wire:key="run-{{ $run->id }}"
                                wire:click="toggle({{ $run->id }})"
                                @class([
                                    'cursor-pointer border-b border-border-default/60 hover:bg-surface-subtle',
                                    'bg-surface-subtle' => $selected?->id === $run->id,
                                ])
                            >
                                <td class="px-3 py-2 font-mono font-semibold text-accent">#{{ $run->id }}</td>
                                <td class="px-3 py-2">
                                    <x-ui.badge :variant="$run->operation_type->isMirror() ? 'accent' : 'default'">
                                        <span class="font-mono text-xs">{{ $run->operation_type->value }}</span>
                                    </x-ui.badge>
                                </td>
                                <td class="px-3 py-2 text-ink">
                                    {{ $run->actor_label ?? $run->actor_type ?? '—' }}
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-muted">
                                    {{ $run->operation_type->isMirror() ? ($run->remote_instance_id ?? 'mirror') : ($run->source ?? '—') }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $run->table_count }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">
                                    {{ $run->total_rows_affected !== null ? number_format($run->total_rows_affected) : '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <x-ui.badge :variant="$statusVariant($run->status->value)">{{ ucfirst($run->status->value) }}</x-ui.badge>
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $fmtDuration($run->duration_ms) }}</td>
                                <td class="px-3 py-2 text-xs text-muted">
                                    {{ ($run->finished_at ?? $run->started_at)?->diffForHumans() ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $runs->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($selected !== null)
        <x-ui.card>
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                <h2 class="font-mono text-sm font-semibold text-accent">#{{ $selected->id }} · {{ $selected->operation_type->value }}</h2>
                <span class="font-mono text-xs text-muted"><span class="text-ink">actor</span> {{ $selected->actor_label ?? $selected->actor_type ?? '—' }}</span>
                @if ($selected->trace_id)
                    <span class="font-mono text-xs text-muted"><span class="text-ink">trace</span> {{ $selected->trace_id }}</span>
                @endif
                <span class="font-mono text-xs text-muted"><span class="text-ink">schedule_run_ref</span> {{ $selected->schedule_run_ref ?? 'null' }}</span>
                @if ($selected->failure_summary)
                    <span class="text-xs text-status-danger">{{ $selected->failure_summary }}</span>
                @endif
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-default text-left text-xs uppercase tracking-wide text-muted">
                            <th class="px-3 py-2 font-medium">{{ __('Table') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Actions') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Effects') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Range') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('Local · remote (observed)') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($selected->tables as $summary)
                            <tr wire:key="summary-{{ $summary->id }}" class="border-b border-border-default/60">
                                <td class="px-3 py-2 font-mono text-xs text-ink">{{ $summary->table_name }}</td>
                                <td class="px-3 py-2">
                                    <span class="flex flex-wrap gap-1">
                                        @foreach ($summary->actions ?? [] as $action)
                                            <x-ui.badge variant="default"><span class="font-mono text-xs">{{ $action }}</span></x-ui.badge>
                                        @endforeach
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-muted">{{ $effects($summary) }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-muted">
                                    @if ($summary->range_kind?->value === 'not_applicable' || $summary->first_key === null)
                                        {{ __('n/a') }}
                                    @else
                                        {{ $summary->first_key }} → {{ $summary->last_key }}
                                        <span class="text-status-warning">{{ $summary->range_kind?->value }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-xs tabular-nums text-muted">
                                    {{ $summary->rows_before !== null ? number_format($summary->rows_before) : '—' }}
                                    ·
                                    {{ $summary->rows_after !== null ? number_format($summary->rows_after) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-center text-sm text-muted">{{ __('No per-table summaries recorded for this run.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
