<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'column',
    'sortBy',
    'sortDir',
    'action' => null,
    'method' => 'sort',
    'align' => 'left', // left | right
    'inactiveIcon' => 'heroicon-m-chevron-up-down',
    'activeIconAsc' => 'heroicon-m-chevron-up',
    'activeIconDesc' => 'heroicon-m-chevron-down',
    'label' => null,
    'title' => null,
])

@php
    $isActive = $sortBy === $column;
    $isAsc = $sortDir === 'asc';
    $ariaSort = $isActive ? ($isAsc ? 'ascending' : 'descending') : 'none';

    $icon = $isActive
        ? ($isAsc ? $activeIconAsc : $activeIconDesc)
        : $inactiveIcon;

    $iconClass = 'w-3 h-3'.($isActive ? '' : ' opacity-40');

    $thBase = 'px-table-cell-x py-table-header-y text-[11px] uppercase tracking-wider font-semibold text-muted';
    $thAlign = $align === 'right' ? ' text-right' : ' text-left';

    $cellBase = $align === 'right' ? 'flex items-center justify-end gap-1' : 'inline-flex items-center gap-1';

    $btnBase = 'inline-flex items-center gap-1 rounded-sm hover:text-ink transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface-subtle';
    $btnActive = $isActive ? ' text-ink' : '';

    $fallbackLabel = $label ?? trim(strip_tags((string) $slot));
    $displayLabel = $label ?? $slot;
    $wireClick = $action ?? $method.'('.\Illuminate\Support\Js::from($column).')';
    $hasAfter = isset($after) && trim((string) $after) !== '';

    $displayTitle = $title
        ?? ($isActive
            ? ($isAsc
                ? __('Sorted ascending by :label - click to reverse', ['label' => $fallbackLabel])
                : __('Sorted descending by :label - click to reverse', ['label' => $fallbackLabel]))
            : __('Sort by :label', ['label' => $fallbackLabel]));
@endphp

<th scope="col" aria-sort="{{ $ariaSort }}" {{ $attributes->except(['wire:click'])->class([$thBase.$thAlign]) }}>
    <span class="{{ $cellBase }}">
        <button
            type="button"
            wire:click="{{ $wireClick }}"
            title="{{ $displayTitle }}"
            class="{{ $btnBase.$btnActive }}"
        >
            {{ $displayLabel }}
            <x-icon :name="$icon" class="{{ $iconClass }}" />
        </button>

        @if($hasAfter)
            {{ $after }}
        @endif
    </span>
</th>
