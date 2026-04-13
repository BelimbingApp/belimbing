@props([
    'value' => null,
    'format' => 'datetime',
])

@php
    $service = app(\App\Base\DateTime\Contracts\DateTimeDisplayService::class);
    $localeContext = app(\App\Base\Locale\Contracts\LocaleContext::class);
    $mode = $service->currentMode();
    $isLocal = $service->isLocalMode();

    if ($value === null) {
        $formatted = '—';
        $iso = null;
    } else {
        $carbon = $value instanceof \DateTimeInterface
            ? \Carbon\Carbon::instance($value)
            : \Carbon\Carbon::parse($value);
        $iso = $carbon->utc()->toIso8601String();

        $formatted = match ($format) {
            'date' => $service->formatDate($value),
            'time' => $service->formatTime($value),
            default => $service->formatDateTime($value),
        };
    }
@endphp

@if ($iso === null)
    <span {{ $attributes }}>{{ $formatted }}</span>
@elseif ($isLocal)
    <time
        {{ $attributes->merge(['datetime' => $iso, 'data-format' => $format, 'data-locale' => $localeContext->forIntl()]) }}
        x-data
        x-init="
            const apply = () => {
                if (window.blbMountDateTimeElement) {
                    window.blbMountDateTimeElement($el, () => ({}));
                    return;
                }

                requestAnimationFrame(apply);
            };

            apply();
        "
        x-effect="window.blbFormatDateTimeElement?.($el)"
    >{{ $formatted }}</time>
@else
    <time {{ $attributes->merge(['datetime' => $iso]) }}>{{ $formatted }}</time>
@endif
