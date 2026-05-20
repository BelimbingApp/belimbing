{{--
    Day tile: a single calendar cell for any (employee × date) or (date) surface
    in BLB. Encodes the day-type tint (Normal/Rest/Off/Holiday) and an optional
    state border for draft/published/preview content. Inner content is supplied
    by the default slot so callers can put a shift code, a leave chip, a punch
    icon, etc.

    Props:
        dayType     — Normal | rest | off | holiday (per AttendanceDay::DAY_TYPE_*)
        emptyLabel  — Label rendered when the slot is empty and the day is non-working
                      (e.g. "Rest", "Off", "Holiday"). Defaults to the vocabulary label.
        state       — 'published' | 'draft' | 'preview' | null. Drives the top-border
                      colour on the slot content pill.
        tooltip     — Optional `title` attribute for hover hints (also used as
                      accessible name when the slot contains only visual chrome).
        empty       — Pass `true` to render the empty placeholder (single dot
                      for Normal, day-type label for non-working). When `false`
                      the default slot is rendered inside a state-bordered pill.

    Slot:
        Default — content rendered inside the state-bordered pill when not empty.

    Usage:
        <x-ui.day-tile day-type="rest" empty />
        <x-ui.day-tile day-type="normal" state="published">
            <span class="text-[12px] font-semibold">DAY</span>
        </x-ui.day-tile>

    The day-type vocabulary helper (`DayTypeVocabulary`) resolves label + surface
    class + ink class so this primitive stays consistent with the roster grid.
--}}
@use('App\Modules\People\Attendance\Support\DayTypeVocabulary')
@props([
    'dayType' => 'normal',
    'state' => null,
    'tooltip' => null,
    'empty' => false,
    'emptyLabel' => null,
])

@php
    $surfaceClass = DayTypeVocabulary::surfaceClass($dayType);
    $inkClass = DayTypeVocabulary::inkClass($dayType);
    $isNonWorking = DayTypeVocabulary::isNonWorking($dayType);
    $stateBorderClass = match ($state) {
        'published' => 'border-t-2 border-status-success',
        'draft' => 'border-t-2 border-status-warning',
        'preview' => 'border-t-2 border-dashed border-status-info',
        default => '',
    };
    $resolvedEmptyLabel = $emptyLabel ?? DayTypeVocabulary::label($dayType);
@endphp

<div {{ $attributes->merge(['class' => $surfaceClass.' group px-1 py-1 text-center align-top', 'title' => $tooltip]) }}>
    @if ($empty)
        @if ($isNonWorking)
            <span class="text-[10px] font-medium uppercase tracking-wide {{ $inkClass }}">{{ $resolvedEmptyLabel }}</span>
        @else
            <span class="text-muted">·</span>
        @endif
    @else
        <div class="inline-flex min-w-14 flex-col items-center rounded-md bg-surface-card {{ $stateBorderClass }} px-1.5 py-0.5">
            {{ $slot }}
        </div>
    @endif
</div>
