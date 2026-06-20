@props([
    'position' => 'top-right',
    // 'sm' = compact (default, used by the UI Reference demo);
    // 'wide' = two-thirds of the viewport for higher-signal action feedback.
    'width' => 'sm',
])

@php
    $positionClasses = match ($position) {
        'top-left' => 'top-4 left-4 items-start',
        default => 'top-4 right-4 items-end',
    };

    $widthClasses = match ($width) {
        // Fixed element: the percentage resolves against the viewport, so this is
        // two-thirds of the screen width.
        'wide' => 'w-2/3',
        default => 'w-full max-w-sm',
    };
@endphp

{{-- z-[60] keeps the notification layer above modals and the Lara overlays
     (both z-50) so notifications fired while a modal is open are never buried. --}}
<div {{ $attributes->class(["pointer-events-none fixed z-[60] flex flex-col gap-2 {$widthClasses} {$positionClasses}"]) }}>
    {{ $slot }}
</div>

