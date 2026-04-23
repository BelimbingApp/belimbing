<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'position' => 'top-right',
])

@php
    $positionClasses = match ($position) {
        'top-left' => 'top-4 left-4 items-start',
        default => 'top-4 right-4 items-end',
    };
@endphp

<div {{ $attributes->class(["pointer-events-none fixed z-40 flex w-full max-w-sm flex-col gap-2 {$positionClasses}"]) }}>
    {{ $slot }}
</div>

