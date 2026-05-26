@props([
    'scope' => 'col', // col | row | colgroup | rowgroup
    'align' => 'left', // left | center | right
])

@php
    $alignment = match ($align) {
        'right' => 'text-right',
        'center' => 'text-center',
        default => 'text-left',
    };
@endphp

<th
    scope="{{ $scope }}"
    {{ $attributes->class([
        'px-table-cell-x py-table-header-y text-[11px] font-semibold text-muted uppercase tracking-wider',
        $alignment,
    ]) }}
>
    {{ $slot }}
</th>
