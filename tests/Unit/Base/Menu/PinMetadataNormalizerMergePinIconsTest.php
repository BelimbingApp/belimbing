<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Menu\Services\PinMetadataNormalizer;

test('mergeMissingPinIcons matches pin URL path to menu href ignoring query string', function (): void {
    $normalizer = new PinMetadataNormalizer;

    $menuItemsFlat = [
        'audit.actions' => [
            'label' => 'Actions',
            'pinLabel' => 'Actions',
            'icon' => 'heroicon-o-bolt',
            'href' => 'https://example.test/admin/audit/actions',
            'route' => 'admin.audit.actions',
        ],
    ];

    $pins = [
        [
            'id' => 1,
            'label' => 'Actions',
            'url' => 'https://example.test/admin/audit/actions?page=2',
            'icon' => null,
        ],
    ];

    $merged = $normalizer->mergeMissingPinIcons($pins, $menuItemsFlat);

    expect($merged[0]['icon'])->toBe('heroicon-o-bolt');
});

test('mergeMissingPinIcons leaves existing pin icon unchanged', function (): void {
    $normalizer = new PinMetadataNormalizer;

    $menuItemsFlat = [
        'a' => [
            'label' => 'A',
            'pinLabel' => 'A',
            'icon' => 'heroicon-o-bolt',
            'href' => 'https://example.test/a',
            'route' => 'a',
        ],
    ];

    $pins = [
        [
            'id' => 1,
            'label' => 'A',
            'url' => 'https://example.test/a',
            'icon' => 'heroicon-o-home',
        ],
    ];

    $merged = $normalizer->mergeMissingPinIcons($pins, $menuItemsFlat);

    expect($merged[0]['icon'])->toBe('heroicon-o-home');
});
