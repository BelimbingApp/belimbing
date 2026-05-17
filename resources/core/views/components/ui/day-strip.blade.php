{{--
    Day strip: a horizontal date-header row for any employees × dates calendar
    surface. Renders a `<thead><tr>` with an optional sticky leading-column
    header followed by one header cell per day in `$days`.

    Designed to sit at the top of a `<table>` whose body rows render employees
    plus `<x-ui.day-tile>` cells. Day headers can highlight today (accent
    underline), weekends (muted), and holidays (`bg-day-holiday`) when those
    flags are present on each day entry.

    Props:
        days          — list<array{date: string, day: string, label: string,
                        day_short?: string, is_today?: bool, is_weekend?: bool,
                        is_holiday?: bool}>
                        The shape returned by `Rosters::rosterGridDays()` after
                        being enriched with the today/weekend/holiday markers.
        leadingLabel  — Optional uppercase label rendered in the sticky first
                        cell (e.g. "Employee"). When null, the first cell is
                        an empty sticky placeholder.
        compact       — When true, columns render at minimum width and the day
                        name collapses to a single letter (M T W T F S S). Use
                        for month-scope views where every pixel matters.

    Usage:
        <table>
            <x-ui.day-strip :days="$days" :leading-label="__('Employee')" />
            <tbody>...rows of <x-ui.day-tile>...</tbody>
        </table>
--}}
@props([
    'days' => [],
    'leadingLabel' => null,
    'compact' => false,
])

@php
    $colMinWidth = $compact ? 'min-w-9' : 'min-w-20';
    $colPadding = $compact ? 'px-0.5' : 'px-1.5';
@endphp

<thead class="bg-surface-subtle/80">
    <tr>
        <th class="sticky left-0 z-10 w-40 min-w-40 bg-surface-subtle/95 px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">
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
            <th class="{{ $headerSurface }} {{ $colMinWidth }} {{ $colPadding }} py-table-header-y text-center text-[11px] font-semibold uppercase tracking-wider {{ $headerInk }} @if($isToday) underline decoration-accent decoration-2 underline-offset-4 @endif" wire:key="day-strip-{{ $day['date'] }}" @if($isHoliday) title="{{ __('Holiday') }}" @endif>
                <div>{{ $dayLabel }}</div>
                @if (! $compact)
                    <div class="font-normal normal-case tracking-normal text-muted">{{ $day['label'] }}</div>
                @endif
            </th>
        @endforeach
    </tr>
</thead>
