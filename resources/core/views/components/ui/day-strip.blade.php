{{--
    Day strip: a horizontal date-header row for any employees × dates calendar
    surface. Renders a `<thead><tr>` with an optional sticky leading-column
    header followed by one header cell per day in `$days`.

    Designed to sit at the top of a `<table>` whose body rows render employees
    plus `<x-ui.day-tile>` cells. Day headers show the day-of-week and date
    label; weekends and "today" can be styled later via class hooks in `$days`.

    Props:
        days          — list<array{date: string, day: string, label: string}>
                        Same shape returned by Rosters::rosterGridDays() so
                        existing consumers can pass it directly.
        leadingLabel  — Optional uppercase label rendered in the sticky first
                        cell (e.g. "Employee"). When null, the first cell is
                        an empty sticky placeholder.

    Usage:
        <table>
            <x-ui.day-strip :days="$days" :leading-label="__('Employee')" />
            <tbody>...rows of <x-ui.day-tile>...</tbody>
        </table>
--}}
@props([
    'days' => [],
    'leadingLabel' => null,
])

<thead class="bg-surface-subtle/80">
    <tr>
        <th class="sticky left-0 z-10 w-40 min-w-40 bg-surface-subtle/95 px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">
            {{ $leadingLabel ?? '' }}
        </th>
        @foreach ($days as $day)
            <th class="min-w-20 px-1.5 py-table-header-y text-center text-[11px] font-semibold uppercase tracking-wider text-muted" wire:key="day-strip-{{ $day['date'] }}">
                <div>{{ $day['day'] }}</div>
                <div class="font-normal normal-case tracking-normal text-muted">{{ $day['label'] }}</div>
            </th>
        @endforeach
    </tr>
</thead>
