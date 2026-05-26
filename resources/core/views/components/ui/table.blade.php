@props([
    'caption' => null,
    'captionPosition' => 'sr-only', // sr-only | top | bottom
    'container' => 'bordered', // bordered | card | flush | plain
    'size' => 'sm', // xs | sm
    'stickyHeader' => false,
    'striped' => false,
    'rowHover' => true,
    'divided' => true,
    'empty' => false,
    'emptyColspan' => 1,
    'emptyMessage' => null,
])

@php
    $wrapperBase = match ($container) {
        'card' => 'overflow-x-auto rounded-2xl border border-border-default bg-surface-card shadow-sm',
        'flush' => 'overflow-x-auto -mx-card-inner px-card-inner',
        'plain' => 'overflow-x-auto',
        default => 'overflow-x-auto rounded-2xl border border-border-default',
    };

    $tableText = match ($size) {
        'xs' => 'text-xs',
        default => 'text-sm',
    };

    $tableClasses = 'min-w-full '.$tableText.($divided ? ' divide-y divide-border-default' : '');
    $captionClasses = match ($captionPosition) {
        'top' => 'caption-top px-table-cell-x py-table-cell-y text-left text-sm font-medium text-ink',
        'bottom' => 'caption-bottom px-table-cell-x py-table-cell-y text-left text-sm text-muted',
        default => 'sr-only',
    };
    $headClasses = 'bg-surface-subtle/80'.($stickyHeader ? ' sticky top-0 z-10 backdrop-blur' : '');
    $bodyClasses = collect([
        'bg-surface-card',
        $divided ? 'divide-y divide-border-default' : null,
        $rowHover ? '[&>tr]:transition-colors [&>tr:hover]:bg-surface-subtle/50' : null,
        $striped ? '[&>tr:nth-child(even)]:bg-surface-subtle/30' : null,
    ])->filter()->implode(' ');
    $footClasses = 'bg-surface-subtle/60';
    $emptyText = $emptyMessage ?? __('No records found.');

    $hasColgroup = isset($colgroup) && trim((string) $colgroup) !== '';
    $hasHead = isset($head) && trim((string) $head) !== '';
    $hasFoot = isset($foot) && trim((string) $foot) !== '';
@endphp

<div {{ $attributes->class([$wrapperBase]) }}>
    <table class="{{ $tableClasses }}">
        @if($caption)
            <caption class="{{ $captionClasses }}">{{ $caption }}</caption>
        @endif

        @if($hasColgroup)
            {{ $colgroup }}
        @endif

        @if($hasHead)
            <thead class="{{ $headClasses }}">
                {{ $head }}
            </thead>
        @else
            <thead class="sr-only">
                <tr>
                    <th scope="col">{{ $caption ?? __('Table content') }}</th>
                </tr>
            </thead>
        @endif

        <tbody class="{{ $bodyClasses }}">
            @if($empty)
                <tr>
                    <td colspan="{{ $emptyColspan }}" class="px-table-cell-x py-8 text-center text-sm text-muted">
                        @isset($emptyState)
                            {{ $emptyState }}
                        @else
                            {{ $emptyText }}
                        @endisset
                    </td>
                </tr>
            @else
                {{ $body ?? $slot }}
            @endif
        </tbody>

        @if($hasFoot)
            <tfoot class="{{ $footClasses }}">
                {{ $foot }}
            </tfoot>
        @endif
    </table>
</div>
