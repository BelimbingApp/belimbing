@if ($shiftTemplateExportJson !== '')
    @include('livewire.people.attendance.policy-studio.partials.template-json-export', [
        'id' => 'attendance-shift-library-template-export',
        'field' => 'shiftTemplateExportJson',
    ])
@endif

<x-ui.card>
    <div class="overflow-hidden rounded-2xl border border-border-default">
        <table class="min-w-full divide-y divide-border-default text-sm">
            <thead class="bg-surface-subtle/80">
                <tr>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Shift') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Schedule') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-default bg-surface-card">
                @forelse ($shiftTemplates as $shift)
                    <tr wire:key="shift-library-{{ $shift->id }}" class="transition hover:bg-surface-subtle/70">
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            <button type="button" class="text-left font-medium text-accent hover:underline" wire:click="editShiftTemplate({{ $shift->id }})">{{ $shift->code }} - {{ $shift->name }}</button>
                            <p class="mt-0.5 text-xs text-muted">{{ trans_choice(':count punch window|:count punch windows', $shift->punchWindows->count(), ['count' => $shift->punchWindows->count()]) }}</p>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top font-mono text-xs text-muted">
                            {{ $shift->starts_at }} → {{ $shift->ends_at }} · {{ trans_choice(':count minute|:count minutes', $shift->expected_work_minutes, ['count' => $shift->expected_work_minutes]) }}
                            @if ($shift->crosses_midnight)
                                <div class="mt-1"><x-ui.badge variant="warning">{{ __('Cross-midnight') }}</x-ui.badge></div>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            <x-ui.badge :variant="$shift->status === 'active' ? 'success' : 'secondary'">{{ __(ucfirst($shift->status)) }}</x-ui.badge>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="editShiftTemplate({{ $shift->id }})">{{ __('Edit') }}</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="duplicateShiftTemplate({{ $shift->id }})">{{ __('Duplicate') }}</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="exportShiftTemplate({{ $shift->id }})">{{ __('Download') }}</x-ui.button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No shift templates configured. Start from a template above.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
