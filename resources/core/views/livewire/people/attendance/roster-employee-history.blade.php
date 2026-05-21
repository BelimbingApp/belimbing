<?php

use App\Modules\People\Attendance\Livewire\RosterEmployeeHistory;

/** @var RosterEmployeeHistory $this */
?>

<div>
    <x-slot name="title">{{ __('Roster Change History') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="$employee ? __(':name — Roster History', ['name' => $employee->displayName()]) : __('Roster Change History')"
            :subtitle="__('All roster cell changes for this employee, in reverse chronological order.')"
        />

        @if (! $employee)
            <x-ui.alert variant="warning">{{ __('No employee selected.') }}</x-ui.alert>
        @else
            {{-- Date filter --}}
            <div class="flex flex-wrap items-end gap-3 rounded-xl border border-border-default bg-surface-card p-4">
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium text-muted">{{ __('From') }}</label>
                    <input type="date" wire:model.live="fromDate"
                           class="rounded-lg border border-border-default bg-surface-card px-3 py-1.5 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium text-muted">{{ __('To') }}</label>
                    <input type="date" wire:model.live="toDate"
                           class="rounded-lg border border-border-default bg-surface-card px-3 py-1.5 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>
            </div>

            {{-- History table --}}
            <div class="overflow-x-auto rounded-2xl border border-border-default">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle">
                        <tr>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Date') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Changed at') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('By') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Action') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Previous') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('New') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Note / Job') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($rows as $log)
                            <tr class="hover:bg-surface-subtle/50">
                                <td class="px-table-cell-x py-table-cell-y font-medium text-ink">
                                    {{ \Carbon\CarbonImmutable::parse($log->date)->format('d M Y') }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-muted">
                                    {{ $log->changed_at?->format('d M Y, H:i') ?? '—' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-muted">
                                    {{ $log->changed_by !== null ? ($userNames[$log->changed_by] ?? __('Unknown')) : __('System') }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    @php($actionVariant = match($log->action) { 'created' => 'success', 'deleted' => 'danger', 'locked' => 'warning', default => 'default' })
                                    <x-ui.badge :variant="$actionVariant">{{ __($log->action) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-muted">
                                    @if ($log->previousShift || $log->previousPolicy)
                                        <span class="font-medium text-ink">{{ $log->previousShift?->code ?? '—' }}</span>
                                        <span class="text-xs"> / {{ $log->previousPolicy?->code ?? '—' }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    @if ($log->newShift || $log->newPolicy)
                                        <span class="font-medium text-ink">{{ $log->newShift?->code ?? '—' }}</span>
                                        <span class="text-xs text-muted"> / {{ $log->newPolicy?->code ?? '—' }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-muted">
                                    @if ($log->note || $log->job)
                                        @if ($log->job)
                                            <span class="mr-1 rounded bg-surface-subtle px-1.5 py-0.5 text-xs font-medium text-ink">{{ $log->job }}</span>
                                        @endif
                                        {{ $log->note }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-table-cell-y text-center text-muted">
                                    {{ __('No change history for this date range.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rows->hasPages())
                <div class="mt-4">
                    {{ $rows->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
