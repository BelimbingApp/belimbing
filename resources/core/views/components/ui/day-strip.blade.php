{{--
    Day strip: a horizontal date-header row for an employees × dates calendar
    table. Renders a `<thead><tr>` with an optional sticky leading-column
    header followed by one scoped header cell per day in `$days`.

    Designed to sit at the top of a `<table>` whose body rows render employees
    plus `<x-ui.day-tile>` cells. Day headers can highlight today (accent
    underline), weekends (muted), and holidays (`bg-day-holiday`) when those
    flags are present on each day entry.

    Props:
        days          — list<array{date: string, day: string, label: string,
                        day_short?: string, is_today?: bool, is_weekend?: bool,
                        is_holiday?: bool}>
        leadingLabel  — Optional uppercase label rendered in the sticky first
                        cell (e.g. "Employee"). When null, the first cell is
                        an empty sticky placeholder.
        compact       — When true, columns render at minimum width and the day
                        name collapses to a single letter.
--}}
@props([
    'days' => [],
    'leadingLabel' => null,
    'compact' => false,
    'clickable' => false,
])

@php
    $colMinWidth = $compact ? 'min-w-9' : 'min-w-20';
    $colPadding = $compact ? 'px-0.5' : 'px-1.5';
@endphp

<thead class="bg-surface-subtle/80">
    <tr>
        <th scope="col" class="sticky left-0 z-10 w-40 min-w-40 bg-surface-subtle/95 px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">
            {{ $leadingLabel ?? '' }}
        </th>
        @foreach ($days as $day)
            @php
                $isToday = $day['is_today'] ?? false;
                $isWeekend = $day['is_weekend'] ?? false;
                $isHoliday = $day['is_holiday'] ?? false;
                $headerSurface = $isHoliday ? 'bg-day-holiday' : '';
                $headerInk = match (true) {
                    $isHoliday => 'text-day-holiday-ink',
                    $isToday => 'text-accent',
                    $isWeekend => 'text-muted',
                    default => 'text-muted',
                };
                $dayLabel = $compact ? ($day['day_short'] ?? substr($day['day'], 0, 1)) : $day['day'];
            @endphp
            <th scope="col" class="{{ $headerSurface }} {{ $colMinWidth }} {{ $colPadding }} py-table-header-y text-center text-[11px] font-semibold uppercase tracking-wider {{ $headerInk }}" wire:key="day-strip-{{ $day['date'] }}" @if($isHoliday) title="{{ __('Holiday') }}" @endif>
                @if ($clickable)
                    <button type="button" @click="$dispatch('show-day-drawer', { date: '{{ $day['date'] }}' })" class="w-full hover:text-accent focus:outline-none focus:ring-2 focus:ring-accent focus:rounded-sm transition-colors">
                        <div>{{ $dayLabel }}</div>
                        @if (! $compact)
                            <div class="font-normal normal-case tracking-normal text-muted">{{ $day['label'] }}</div>
                        @endif
                    </button>
                @else
                    <div>{{ $dayLabel }}</div>
                    @if (! $compact)
                        <div class="font-normal normal-case tracking-normal text-muted">{{ $day['label'] }}</div>
                    @endif
                @endif
            </th>
        @endforeach
    </tr>
</thead>
