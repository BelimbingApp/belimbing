@props([
    'value' => null,
    'fallback' => null,
    'fallbackTitle' => null,
])

{{--
    Relative timestamp that keeps aging while the page is open.

    The server text is rendered inline so a no-JS read still gets an answer;
    resources/core/js/relative-time.js re-derives it from `datetime` on a timer.
    The absolute time is always one hover away, because "2 hours ago" is not an
    answer to "which commit is this".
--}}
@php
    $service = app(\App\Base\DateTime\Contracts\DateTimeDisplayService::class);
    $localeContext = app(\App\Base\Locale\Contracts\LocaleContext::class);

    $carbon = $value !== null
        ? ($value instanceof \DateTimeInterface ? \Carbon\Carbon::instance($value) : \Carbon\Carbon::parse($value))
        : null;
@endphp

@if ($carbon === null)
    <span {{ $attributes->merge($fallbackTitle !== null ? ['title' => $fallbackTitle] : []) }}>{{ $fallback ?? __('Time unavailable') }}</span>
@else
    <time
        {{ $attributes->merge([
            'datetime' => $carbon->utc()->toIso8601String(),
            'title' => $service->formatDateTime($carbon),
            'data-blb-relative' => 'true',
            'data-locale' => $localeContext->forIntl(),
        ]) }}
    >{{ $carbon->diffForHumans(['parts' => 2]) }}</time>
@endif
