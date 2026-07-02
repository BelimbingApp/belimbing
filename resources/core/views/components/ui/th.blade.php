@props([
    'scope' => 'col', // col | row | colgroup | rowgroup
    'align' => 'left', // left | center | right
    'numeric' => false, // right-align + tabular-nums for numeric columns
    'width' => null, // CSS width (e.g. "120px", "8rem")
    'nowrap' => false, // prevent header content wrapping
])

@php
    $effectiveAlign = $numeric ? 'right' : $align;
    $alignment = match ($effectiveAlign) {
        'right' => 'text-right',
        'center' => 'text-center',
        default => 'text-left',
    };
    $thAttrs = $attributes->class([
        'px-table-cell-x py-table-header-y text-[11px] font-semibold text-muted uppercase tracking-wider',
        $alignment,
        'tabular-nums' => $numeric,
        'whitespace-nowrap' => $nowrap,
    ]);
    if ($width) {
        $thAttrs = $thAttrs->style("width: {$width}");
    }
@endphp

<th
    scope="{{ $scope }}"
    {{ $thAttrs }}
>
    {{ $slot }}
</th>
