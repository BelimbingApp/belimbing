<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/**
 * Help toggle button (customizable icon).
 *
 * A small circular button for triggering contextual help. Typically used
 * inside x-ui.page-header (which handles the panel), but can also be
 * used standalone with your own Alpine toggle logic.
 *
 * Usage (standalone):
 *   <div x-data="{ show: false }">
 *       <x-ui.help @click="show = !show" />
 *       <div x-show="show">Help content...</div>
 *   </div>
 *
 * Usage (via page-header — just provide the help slot content):
 *   <x-ui.page-header title="..." subtitle="...">
 *       <x-slot name="help">Help content here...</x-slot>
 *   </x-ui.page-header>
 */
?>

@props([
    'size' => 'md',
    'icon' => 'heroicon-o-question-mark-circle',
])

@php
    $sizeClasses = match($size) {
        'sm' => 'w-3.5 h-3.5',
        'md' => 'w-4 h-4',
        'lg' => 'w-5 h-5',
        default => 'w-4 h-4',
    };
@endphp

<button
    type="button"
    {{ $attributes->class([
        'inline-flex items-center justify-center text-muted hover:text-ink focus:text-accent transition-all',
        'focus:outline-none rounded-full p-0.5',
        'focus:ring-2 focus:ring-accent/20',
    ]) }}
    aria-label="{{ __('Help') }}"
>
    <x-icon :name="$icon" class="{{ $sizeClasses }}" />
</button>
