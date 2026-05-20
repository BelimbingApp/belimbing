@use('App\Modules\People\Attendance\Support\DayTypeVocabulary')
@php
    $showPreviewLegend = $showPreviewLegend ?? true;
    $compact = $compact ?? false;
    $gridIntro = $gridIntro ?? __('Existing assignments show draft or published state; rest, off, and holiday days surface from each employee\'s work calendar.');
    $cellMinWidth = $compact ? 'min-w-9' : 'min-w-14';
    $showDayDrawer = $showDayDrawer ?? true;
    $lockedDates = $lockedDates ?? [];
    $actualOutcomes = $actualOutcomes ?? [];
    $actualMode = $actualMode ?? false;
    $dayDrawerData = [];
    if ($showDayDrawer && !empty($rosterGridRows)) {
        foreach ($rosterGridDays as $day) {
            $entries = [];
            foreach ($rosterGridRows as $row) {
                $cell = $row['cells'][$day['date']] ?? null;
                if (! $cell) {
                    continue;
                }
                $entries[] = [
                    'name'    => $row['employee']->displayName(),
                    'shift'   => $cell['label'] ?? '',
                    'state'   => $cell['state'] ?? 'empty',
                    'dayType' => $cell['day_type'] ?? 'normal',
                    'title'   => $cell['title'] ?? '',
                    'empty'   => ($cell['state'] ?? 'empty') === 'empty',
                ];
            }
            $dayDrawerData[$day['date']] = [
                'label'     => \Carbon\CarbonImmutable::parse($day['date'])->format('j M, D'),
                'isHoliday' => $day['is_holiday'] ?? false,
                'isWeekend' => $day['is_weekend'] ?? false,
                'entries'   => $entries,
                'assigned'  => count(array_filter($entries, fn ($e) => ! $e['empty'])),
            ];
        }
    }
@endphp

<style>
.roster-selected { outline: 2px solid var(--color-accent, #6366f1); outline-offset: -2px; }
.roster-fill-preview { background-color: color-mix(in srgb, var(--color-accent, #6366f1) 12%, transparent); }
.roster-copied { outline: 2px dashed var(--color-accent, #6366f1); outline-offset: -2px; }

@media print {
    @page { size: A4 landscape; margin: 10mm; }
    /* Hide layout chrome */
    body > div > div > div:first-child,
    body > div > div > div:last-child { display: none !important; }
    main { overflow: visible !important; padding: 0 !important; }
    /* Hide non-roster UI within the page */
    .roster-print-hide { display: none !important; }
    /* Remove backgrounds; use borders for photocopier safety */
    .roster-grid-print table td,
    .roster-grid-print table th {
        background: white !important;
        border: 1px solid #000 !important;
        font-size: 10pt !important;
    }
    .roster-grid-print .roster-grid-cell-label { font-size: 10pt !important; font-weight: 600; }
    .roster-grid-print table { border-collapse: collapse !important; width: 100% !important; }
}
</style>

<div x-data="rosterGrid(@js($dayDrawerData ?? []))"
     @show-day-drawer.window="openDrawer($event.detail.date)"
     @grid-cell-select.window="handleCellSelect($event.detail)"
     @keydown.window="handleKeydown($event)"
     class="relative">

<div class="roster-print-hide flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
    @if ($gridIntro)
        <p class="text-sm text-muted">{{ $gridIntro }}</p>
    @else
        <div></div>
    @endif
    <div class="flex flex-wrap items-center gap-2">
        <x-ui.badge variant="success">{{ __('Published') }}</x-ui.badge>
        <x-ui.badge variant="warning">{{ __('Draft') }}</x-ui.badge>
        @if ($showPreviewLegend)
            <x-ui.badge variant="info">{{ __('Preview') }}</x-ui.badge>
        @endif
        <span class="text-[11px] text-muted">·</span>
        <span class="inline-flex items-center gap-1 rounded-full bg-day-rest px-2 py-0.5 text-[11px] font-medium text-day-rest-ink">{{ __('Rest') }}</span>
        <span class="inline-flex items-center gap-1 rounded-full bg-day-off px-2 py-0.5 text-[11px] font-medium text-day-off-ink">{{ __('Off') }}</span>
        <span class="inline-flex items-center gap-1 rounded-full bg-day-holiday px-2 py-0.5 text-[11px] font-medium text-day-holiday-ink">{{ __('Holiday') }}</span>
    </div>
</div>

<div class="roster-grid-print mt-4 overflow-x-auto rounded-2xl border border-border-default">
    <table class="min-w-full divide-y divide-border-default text-xs">
        <x-ui.day-strip :days="$rosterGridDays" :leading-label="__('Employee')" :compact="$compact" :clickable="$showDayDrawer" />
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($rosterGridRows as $row)
                @php($employee = $row['employee'])
                @if ($loop->first || $row['group'] !== $rosterGridRows[$loop->index - 1]['group'])
                    <tr wire:key="roster-grid-group-{{ $loop->index }}">
                        <td colspan="{{ count($rosterGridDays) + 1 }}" class="sticky left-0 z-10 bg-surface-subtle px-table-cell-x py-1 text-[11px] font-semibold uppercase tracking-wide text-muted">
                            {{ $row['group'] }}
                        </td>
                    </tr>
                @endif
                <tr wire:key="roster-grid-row-{{ $employee->id }}" data-row-employee="{{ $employee->id }}" class="hover:bg-surface-subtle/50">
                    <td class="sticky left-0 z-10 w-40 min-w-40 bg-surface-card px-table-cell-x py-1.5 align-top">
                        <div class="truncate text-sm font-medium text-ink" title="{{ $employee->full_name }}">{{ $employee->displayName() }}</div>
                    </td>
                    @foreach ($rosterGridDays as $day)
                        @php($cell = $row['cells'][$day['date']])
                        @php($dayType = $cell['day_type'] ?? 'normal')
                        @php($dayTypeInk = DayTypeVocabulary::inkClass($dayType))
                        @php($isEmpty = $cell['state'] === 'empty')
                        @php($cellShiftId = (int) ($cell['shift_template_id'] ?? 0))
                        @php($cellPolicyId = (int) ($cell['policy_group_id'] ?? 0))
                        @php($isDateLocked = isset($lockedDates[$day['date']]))
                        @php($actualOutcome = $actualMode ? ($actualOutcomes[$employee->id . '-' . $day['date']] ?? null) : null)
                        @php($actualTint = match($actualOutcome) { 'absent' => 'ring-2 ring-red-400 ring-inset', 'late', 'early' => 'ring-2 ring-warning ring-inset', 'matched' => 'ring-2 ring-success ring-inset', default => '' })
                        @php($isEditable = $canManage && ! $isDateLocked && ! $actualMode)
                        <td wire:key="roster-grid-cell-{{ $employee->id }}-{{ $day['date'] }}"
                            class="relative p-0 align-top {{ $actualTint }}"
                            data-employee="{{ $employee->id }}"
                            data-date="{{ $day['date'] }}"
                            data-shift="{{ $cellShiftId }}"
                            data-policy="{{ $cellPolicyId }}"
                            data-state="{{ $cell['state'] }}"
                            @if($isEditable)
                                x-data="{ open: false, shift: {{ $cellShiftId }}, policy: {{ $cellPolicyId }} }"
                                :data-cell-shift="{{ $cellShiftId }}"
                                :data-cell-policy="{{ $cellPolicyId }}"
                                @click.capture="
                                    if ($event.shiftKey || $event.ctrlKey || $event.metaKey) {
                                        $dispatch('grid-cell-select', { empId: {{ $employee->id }}, date: '{{ $day['date'] }}', extendShift: $event.shiftKey, toggle: $event.ctrlKey || $event.metaKey });
                                        $event.stopPropagation();
                                    } else {
                                        $dispatch('grid-cell-select', { empId: {{ $employee->id }}, date: '{{ $day['date'] }}', extendShift: false, toggle: false });
                                    }
                                "
                            @endif
                        >
                            @if ($isEditable)
                                <button
                                    type="button"
                                    x-ref="overrideTrigger"
                                    @click="shift = parseInt($root.dataset.cellShift) || 0; policy = parseInt($root.dataset.cellPolicy) || 0; open = ! open; if (! open) { $nextTick(() => $refs.overrideTrigger?.focus()) }"
                                    :aria-expanded="open"
                                    aria-label="{{ __('Edit override :date for :employee', ['date' => $day['date'], 'employee' => $employee->displayName()]) }}"
                                    class="block w-full {{ $cellMinWidth }} cursor-pointer text-center focus:outline-none focus:ring-2 focus:ring-accent focus:ring-inset focus:rounded-md"
                                >
                                    <x-ui.day-tile
                                        :day-type="$dayType"
                                        :state="$isEmpty ? null : $cell['state']"
                                        :tooltip="$cell['title']"
                                        :empty="$isEmpty"
                                        :empty-label="$cell['label']"
                                    >
                                        <span class="text-[12px] font-semibold leading-tight text-ink">{{ $cell['label'] }}</span>
                                        @if ($cell['on_non_working_day'] ?? false)
                                            <span class="text-[9px] font-medium uppercase leading-tight tracking-wide {{ $dayTypeInk }}">{{ $cell['day_type_label'] }}</span>
                                        @endif
                                        @if (! $isEmpty && ($cell['on_non_working_day'] ?? false))
                                            <span class="text-[9px] font-medium uppercase leading-tight tracking-wide text-warning" title="{{ __('Shift on non-working day') }}">⚠</span>
                                        @endif
                                    </x-ui.day-tile>
                                </button>
                                {{-- Fill handle: shown on focused cell, used to drag-fill adjacent cells --}}
                                <div class="roster-fill-handle absolute bottom-0 right-0 z-10 hidden h-3 w-3 -translate-x-px -translate-y-px cursor-crosshair rounded-full border-2 border-white bg-accent shadow-sm"
                                     data-fill-handle="{{ $employee->id }}:{{ $day['date'] }}"
                                     onmousedown="window.rosterGrid?.startFillDrag(event, '{{ $employee->id }}', '{{ $day['date'] }}')"></div>
                                <section x-show="open" x-cloak x-ref="overrideDialog" x-effect="if (open) { $nextTick(() => $refs.overrideDialog?.focus()) }" @keydown.escape.prevent.stop="open = false; $nextTick(() => $refs.overrideTrigger?.focus())" @click.outside="if (open) { open = false; $nextTick(() => $refs.overrideTrigger?.focus()) }" x-transition.origin.top.left class="absolute left-1/2 z-30 mt-1 w-56 -translate-x-1/2 rounded-2xl border border-border-default bg-surface-card p-3 text-left shadow-lg" role="dialog" tabindex="-1" aria-label="{{ __('Override :date', ['date' => $day['date']]) }}">
                                    <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Override') }} {{ \Carbon\CarbonImmutable::parse($day['date'])->format('j M') }}</div>
                                    <div class="mt-2 space-y-2">
                                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-muted">
                                            {{ __('Shift') }}
                                            <select x-model.number="shift" class="mt-1 w-full rounded-2xl border border-border-default bg-surface-card px-2 py-1 text-sm text-ink">
                                                <option value="0">{{ __('Choose shift') }}</option>
                                                @foreach ($shiftTemplates as $shift)
                                                    <option value="{{ $shift->id }}">{{ $shift->code }} — {{ $shift->name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-muted">
                                            {{ __('Policy') }}
                                            <select x-model.number="policy" class="mt-1 w-full rounded-2xl border border-border-default bg-surface-card px-2 py-1 text-sm text-ink">
                                                <option value="0">{{ __('Choose policy') }}</option>
                                                @foreach ($policyGroups as $group)
                                                    <option value="{{ $group->id }}">{{ $group->code }} — {{ $group->name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                    <div class="mt-3 flex justify-end gap-2">
                                        <button type="button" @click="open = false; $nextTick(() => $refs.overrideTrigger?.focus())" class="text-xs font-medium text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ __('Cancel') }}</button>
                                        <button type="button" @click="$wire.saveCellOverride({{ $employee->id }}, '{{ $day['date'] }}', shift, policy).then(() => { open = false; $nextTick(() => $refs.overrideTrigger?.focus()) })" :disabled="! shift || ! policy" class="rounded-lg bg-accent px-2.5 py-1 text-xs font-semibold text-accent-on disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1">{{ __('Save') }}</button>
                                    </div>
                                </section>
                            @else
                                {{-- Read-only tile: locked period, actual mode, or non-manager --}}
                                <x-ui.day-tile
                                    :day-type="$dayType"
                                    :state="$isEmpty ? null : $cell['state']"
                                    :tooltip="$isDateLocked ? __('Locked period') : $cell['title']"
                                    :empty="$isEmpty"
                                    :empty-label="$cell['label']"
                                >
                                    <span class="text-[12px] font-semibold leading-tight {{ $isDateLocked && ! $isEmpty ? 'text-muted' : 'text-ink' }}">{{ $cell['label'] }}</span>
                                    @if ($cell['on_non_working_day'] ?? false)
                                        <span class="text-[9px] font-medium uppercase leading-tight tracking-wide {{ $dayTypeInk }}">{{ $cell['day_type_label'] }}</span>
                                    @endif
                                    @if ($actualOutcome && $actualOutcome !== 'matched' && $actualOutcome !== 'no_record')
                                        <span class="text-[9px] font-medium uppercase leading-tight tracking-wide {{ $actualOutcome === 'absent' ? 'text-danger' : 'text-warning' }}">{{ __($actualOutcome) }}</span>
                                    @endif
                                </x-ui.day-tile>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($rosterGridDays) + 1 }}" class="px-table-cell-x py-table-cell-y text-sm text-muted">
                        {{ __('No employees available for the roster grid.') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($showDayDrawer)
{{-- Day drawer: slides in when a date column header is clicked --}}
<div
    x-show="activeDate"
    x-cloak
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 translate-x-4"
    x-transition:enter-end="opacity-100 translate-x-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100 translate-x-0"
    x-transition:leave-end="opacity-0 translate-x-4"
    @keydown.escape.window="closeDrawer()"
    class="absolute right-0 top-10 z-20 w-72 rounded-2xl border border-border-default bg-surface-card shadow-lg"
>
    <template x-if="activeDay">
        <div>
            <div class="flex items-start justify-between gap-2 border-b border-border-default px-4 py-3">
                <div>
                    <div class="text-sm font-semibold text-ink" x-text="activeDay.label"></div>
                    <template x-if="activeDay.isHoliday">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-day-holiday px-2 py-0.5 text-[10px] font-medium text-day-holiday-ink">{{ __('Holiday') }}</span>
                    </template>
                    <template x-if="!activeDay.isHoliday && activeDay.isWeekend">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-day-rest px-2 py-0.5 text-[10px] font-medium text-day-rest-ink">{{ __('Weekend') }}</span>
                    </template>
                </div>
                <button type="button" @click="closeDrawer()" class="mt-0.5 rounded-md text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="h-4 w-4" />
                </button>
            </div>

            <div class="max-h-80 overflow-y-auto px-4 py-2">
                <template x-if="activeDay.entries.length === 0">
                    <p class="py-4 text-center text-sm text-muted">{{ __('No employees in the current view.') }}</p>
                </template>
                <template x-for="(entry, i) in activeDay.entries" :key="i">
                    <div class="flex items-center justify-between gap-2 border-b border-border-default/50 py-2 last:border-0">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-ink" x-text="entry.name"></div>
                            <div class="text-[11px] text-muted" x-text="entry.title || entry.shift"></div>
                        </div>
                        <template x-if="!entry.empty && entry.state === 'published'">
                            <span class="shrink-0 rounded-full bg-status-success/10 px-2 py-0.5 text-[10px] font-semibold text-status-success">{{ __('Published') }}</span>
                        </template>
                        <template x-if="!entry.empty && entry.state === 'draft'">
                            <span class="shrink-0 rounded-full bg-status-warning/10 px-2 py-0.5 text-[10px] font-semibold text-status-warning">{{ __('Draft') }}</span>
                        </template>
                        <template x-if="entry.empty">
                            <span class="shrink-0 text-[11px] text-muted">—</span>
                        </template>
                    </div>
                </template>
            </div>

            <div class="border-t border-border-default px-4 py-2.5">
                <span class="text-[11px] text-muted">
                    <span class="font-semibold text-ink" x-text="activeDay.assigned"></span>
                    {{ __('assigned') }}
                </span>
            </div>
        </div>
    </template>
</div>
@endif

</div>{{-- /Alpine rosterGrid wrapper --}}

<script>
function rosterGrid(dayData) {
    return {
        // Day drawer
        dayData: dayData || {},
        activeDate: null,
        get activeDay() { return this.dayData[this.activeDate] ?? null; },
        openDrawer(date) { this.activeDate = date; },
        closeDrawer() { this.activeDate = null; },

        // Grid layout (built from DOM on init)
        employees: [],  // ordered employee ID strings
        dates: [],      // ordered date strings (Y-m-d)

        // Selection state
        selection: [],  // [{empId, date}]
        anchor: null,   // {empIdx, dateIdx}
        focus: null,    // {empIdx, dateIdx}

        // Copy buffer
        copyBuffer: null,  // null | [{empOffset, dateOffset, shift, policy}]
        copySourceKeys: [], // 'empId:date' strings for marching-ants display

        // Fill drag state
        dragging: false,
        fillSourceKeys: [],   // 'empId:date' strings being dragged from
        fillPreviewKeys: [],  // 'empId:date' strings in the drag preview

        init() {
            window.rosterGrid = this;
            this.$nextTick(() => this._buildLayout());
            this.$el.addEventListener('livewire:updated', () => {
                this.$nextTick(() => { this._buildLayout(); this._highlight(); });
            });
        },

        _buildLayout() {
            const table = this.$el.querySelector('table');
            if (!table) return;
            this.dates = Array.from(table.querySelectorAll('thead th[data-col-date]')).map(th => th.dataset.colDate);
            this.employees = Array.from(table.querySelectorAll('tbody tr[data-row-employee]')).map(tr => tr.dataset.rowEmployee);
        },

        _td(empId, date) {
            return this.$el.querySelector(`td[data-employee="${empId}"][data-date="${date}"]`);
        },

        _cellData(empId, date) {
            const td = this._td(empId, date);
            if (!td) return null;
            return { shift: parseInt(td.dataset.shift) || 0, policy: parseInt(td.dataset.policy) || 0, state: td.dataset.state || 'empty' };
        },

        _key(empId, date) { return `${empId}:${date}`; },

        _idx(empId, date) {
            const eIdx = this.employees.indexOf(String(empId));
            const dIdx = this.dates.indexOf(date);
            return (eIdx === -1 || dIdx === -1) ? null : { empIdx: eIdx, dateIdx: dIdx };
        },

        _range(from, to) {
            const out = [];
            const step = from <= to ? 1 : -1;
            for (let i = from; i !== to + step; i += step) out.push(i);
            return out;
        },

        _highlight() {
            this.$el.querySelectorAll('td.roster-selected').forEach(td => td.classList.remove('roster-selected'));
            this.$el.querySelectorAll('td.roster-fill-preview').forEach(td => td.classList.remove('roster-fill-preview'));
            this.$el.querySelectorAll('td.roster-copied').forEach(td => td.classList.remove('roster-copied'));
            this.$el.querySelectorAll('.roster-fill-handle').forEach(h => h.classList.add('hidden'));

            this.selection.forEach(({ empId, date }) => this._td(empId, date)?.classList.add('roster-selected'));
            this.fillPreviewKeys.forEach(k => {
                const [empId, date] = k.split(':');
                this._td(empId, date)?.classList.add('roster-fill-preview');
            });
            this.copySourceKeys.forEach(k => {
                const [empId, date] = k.split(':');
                this._td(empId, date)?.classList.add('roster-copied');
            });

            // Show fill handle on focused cell
            if (this.focus && this.selection.length > 0) {
                const empId = this.employees[this.focus.empIdx];
                const date = this.dates[this.focus.dateIdx];
                const handle = this.$el.querySelector(`[data-fill-handle="${empId}:${date}"]`);
                handle?.classList.remove('hidden');
            }
        },

        // Cell selection
        handleCellSelect({ empId, date, extendShift, toggle }) {
            const idx = this._idx(empId, date);
            if (!idx) return;

            if (extendShift && this.anchor) {
                // Rectangle from anchor to target
                const minE = Math.min(this.anchor.empIdx, idx.empIdx);
                const maxE = Math.max(this.anchor.empIdx, idx.empIdx);
                const minD = Math.min(this.anchor.dateIdx, idx.dateIdx);
                const maxD = Math.max(this.anchor.dateIdx, idx.dateIdx);
                this.selection = [];
                for (let e = minE; e <= maxE; e++) {
                    for (let d = minD; d <= maxD; d++) {
                        this.selection.push({ empId: this.employees[e], date: this.dates[d] });
                    }
                }
                this.focus = idx;
            } else if (toggle) {
                const key = this._key(empId, date);
                const exists = this.selection.findIndex(s => this._key(s.empId, s.date) === key);
                if (exists >= 0) {
                    this.selection.splice(exists, 1);
                } else {
                    this.selection.push({ empId: String(empId), date });
                    this.anchor = idx;
                    this.focus = idx;
                }
            } else {
                this.selection = [{ empId: String(empId), date }];
                this.anchor = idx;
                this.focus = idx;
            }

            this._highlight();
        },

        clearSelection() {
            this.selection = [];
            this.anchor = null;
            this.focus = null;
            this.copyBuffer = null;
            this.copySourceKeys = [];
            this._highlight();
        },

        // Copy
        copySelection() {
            if (this.selection.length === 0) return;
            const minE = Math.min(...this.selection.map(s => this._idx(s.empId, s.date)?.empIdx ?? 0));
            const minD = Math.min(...this.selection.map(s => this._idx(s.empId, s.date)?.dateIdx ?? 0));
            this.copyBuffer = this.selection.map(s => {
                const idx = this._idx(s.empId, s.date);
                const data = this._cellData(s.empId, s.date);
                return { empOffset: (idx?.empIdx ?? 0) - minE, dateOffset: (idx?.dateIdx ?? 0) - minD, shift: data?.shift || 0, policy: data?.policy || 0 };
            });
            this.copySourceKeys = this.selection.map(s => this._key(s.empId, s.date));
            this._highlight();
        },

        // Paste
        pasteAtFocus() {
            if (!this.copyBuffer || !this.focus) return;
            const overrides = this.copyBuffer.map(c => {
                const eIdx = this.focus.empIdx + c.empOffset;
                const dIdx = this.focus.dateIdx + c.dateOffset;
                if (eIdx < 0 || eIdx >= this.employees.length || dIdx < 0 || dIdx >= this.dates.length) return null;
                return { employee_id: parseInt(this.employees[eIdx]), date: this.dates[dIdx], shift_template_id: c.shift, policy_group_id: c.policy };
            }).filter(Boolean);
            if (overrides.length > 0) this.$wire.saveCellOverrides(overrides);
            this.copySourceKeys = [];
            this.copyBuffer = null;
            this._highlight();
        },

        // Delete
        deleteSelection() {
            if (this.selection.length === 0) return;
            const hasPublished = this.selection.some(s => this._cellData(s.empId, s.date)?.state === 'published');
            if (hasPublished && !confirm('Some selected cells are published. Clear them and return to draft?')) return;
            const overrides = this.selection.map(s => ({ employee_id: parseInt(s.empId), date: s.date, shift_template_id: 0, policy_group_id: 0 }));
            this.$wire.saveCellOverrides(overrides);
            this.clearSelection();
        },

        // Fill drag
        startFillDrag(event, empId, date) {
            event.preventDefault();
            event.stopPropagation();
            if (this.selection.length === 0) {
                this.selection = [{ empId: String(empId), date }];
                this.anchor = this._idx(empId, date);
                this.focus = this.anchor;
            }
            this.dragging = true;
            this.fillSourceKeys = this.selection.map(s => this._key(s.empId, s.date));
            this.fillPreviewKeys = [...this.fillSourceKeys];
            this._highlight();

            const onMove = (e) => {
                const el = document.elementFromPoint(e.clientX, e.clientY);
                const td = el?.closest?.('td[data-employee]');
                if (td) this._updateFillPreview(td.dataset.employee, td.dataset.date);
            };
            const onUp = (e) => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                const el = document.elementFromPoint(e.clientX, e.clientY);
                const td = el?.closest?.('td[data-employee]');
                if (td) this._commitFill(td.dataset.employee, td.dataset.date);
                this.dragging = false;
                this.fillPreviewKeys = [];
                this._highlight();
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },

        _updateFillPreview(targetEmpId, targetDate) {
            const targetIdx = this._idx(targetEmpId, targetDate);
            if (!targetIdx) return;

            const srcEIdxs = [...new Set(this.selection.map(s => this._idx(s.empId, s.date)?.empIdx).filter(x => x != null))];
            const srcDIdxs = [...new Set(this.selection.map(s => this._idx(s.empId, s.date)?.dateIdx).filter(x => x != null))].sort((a, b) => a - b);
            const minSE = Math.min(...srcEIdxs), maxSE = Math.max(...srcEIdxs);
            const minSD = Math.min(...srcDIdxs), maxSD = Math.max(...srcDIdxs);

            const keys = new Set(this.fillSourceKeys);
            if (targetIdx.dateIdx > maxSD) {
                srcEIdxs.forEach(e => { this._range(maxSD + 1, targetIdx.dateIdx).forEach(d => { if (d < this.dates.length) keys.add(this._key(this.employees[e], this.dates[d])); }); });
            } else if (targetIdx.dateIdx < minSD) {
                srcEIdxs.forEach(e => { this._range(targetIdx.dateIdx, minSD - 1).forEach(d => { if (d >= 0) keys.add(this._key(this.employees[e], this.dates[d])); }); });
            } else if (targetIdx.empIdx > maxSE) {
                srcDIdxs.forEach(d => { this._range(maxSE + 1, targetIdx.empIdx).forEach(e => { if (e < this.employees.length) keys.add(this._key(this.employees[e], this.dates[d])); }); });
            } else if (targetIdx.empIdx < minSE) {
                srcDIdxs.forEach(d => { this._range(targetIdx.empIdx, minSE - 1).forEach(e => { if (e >= 0) keys.add(this._key(this.employees[e], this.dates[d])); }); });
            }
            this.fillPreviewKeys = Array.from(keys);
            this._highlight();
        },

        _detectCyclePeriod(seq) {
            const n = seq.length;
            for (let p = 1; p <= Math.min(14, Math.floor(n / 2)); p++) {
                if (seq.every((_, i) => i < p || seq[i].shift === seq[i % p].shift)) return p;
            }
            return n;
        },

        _commitFill(targetEmpId, targetDate) {
            const targetIdx = this._idx(targetEmpId, targetDate);
            if (!targetIdx || this.selection.length === 0) return;

            const srcEIdxs = [...new Set(this.selection.map(s => this._idx(s.empId, s.date)?.empIdx).filter(x => x != null))].sort((a, b) => a - b);
            const srcDIdxs = [...new Set(this.selection.map(s => this._idx(s.empId, s.date)?.dateIdx).filter(x => x != null))].sort((a, b) => a - b);
            const minSE = Math.min(...srcEIdxs), maxSE = Math.max(...srcEIdxs);
            const minSD = Math.min(...srcDIdxs), maxSD = Math.max(...srcDIdxs);

            // Source sequence for cycle detection (first row's shifts ordered by date)
            const srcSeq = srcDIdxs.map(d => this._cellData(this.employees[srcEIdxs[0]], this.dates[d]) || { shift: 0, policy: 0 });
            const period = this._detectCyclePeriod(srcSeq);

            const overrides = [];

            if (targetIdx.dateIdx > maxSD) {
                const fillDs = this._range(maxSD + 1, targetIdx.dateIdx);
                srcEIdxs.forEach(e => {
                    fillDs.forEach((d, i) => {
                        if (d >= this.dates.length) return;
                        const src = this._cellData(this.employees[srcEIdxs[e - minSE] ?? srcEIdxs[0]], this.dates[srcDIdxs[i % period]]);
                        if (!src || !src.shift || !src.policy) return;
                        overrides.push({ employee_id: parseInt(this.employees[e]), date: this.dates[d], shift_template_id: src.shift, policy_group_id: src.policy });
                    });
                });
            } else if (targetIdx.dateIdx < minSD) {
                const fillDs = this._range(targetIdx.dateIdx, minSD - 1).reverse();
                srcEIdxs.forEach(e => {
                    fillDs.forEach((d, i) => {
                        if (d < 0) return;
                        const src = this._cellData(this.employees[srcEIdxs[e - minSE] ?? srcEIdxs[0]], this.dates[srcDIdxs[i % period]]);
                        if (!src || !src.shift || !src.policy) return;
                        overrides.push({ employee_id: parseInt(this.employees[e]), date: this.dates[d], shift_template_id: src.shift, policy_group_id: src.policy });
                    });
                });
            } else if (targetIdx.empIdx > maxSE) {
                const fillEs = this._range(maxSE + 1, targetIdx.empIdx);
                fillEs.forEach((e, rowI) => {
                    srcDIdxs.forEach((d, colI) => {
                        const srcE = srcEIdxs[rowI % srcEIdxs.length];
                        const src = this._cellData(this.employees[srcE], this.dates[d]);
                        if (!src || !src.shift || !src.policy) return;
                        overrides.push({ employee_id: parseInt(this.employees[e]), date: this.dates[d], shift_template_id: src.shift, policy_group_id: src.policy });
                    });
                });
            } else if (targetIdx.empIdx < minSE) {
                const fillEs = this._range(targetIdx.empIdx, minSE - 1).reverse();
                fillEs.forEach((e, rowI) => {
                    srcDIdxs.forEach((d, colI) => {
                        const srcE = srcEIdxs[rowI % srcEIdxs.length];
                        const src = this._cellData(this.employees[srcE], this.dates[d]);
                        if (!src || !src.shift || !src.policy) return;
                        overrides.push({ employee_id: parseInt(this.employees[e]), date: this.dates[d], shift_template_id: src.shift, policy_group_id: src.policy });
                    });
                });
            }

            if (overrides.length > 0) this.$wire.saveCellOverrides(overrides);
        },

        // Keyboard
        handleKeydown(event) {
            if (this.selection.length === 0 && !this.copyBuffer) return;
            if (['INPUT', 'SELECT', 'TEXTAREA'].includes(event.target.tagName)) return;

            if ((event.ctrlKey || event.metaKey) && event.key === 'c') {
                this.copySelection();
                event.preventDefault();
            } else if ((event.ctrlKey || event.metaKey) && event.key === 'v') {
                this.pasteAtFocus();
                event.preventDefault();
            } else if (event.key === 'Delete' || event.key === 'Backspace') {
                this.deleteSelection();
                event.preventDefault();
            } else if (event.key === 'Escape') {
                this.clearSelection();
            }
        },
    };
}
</script>
