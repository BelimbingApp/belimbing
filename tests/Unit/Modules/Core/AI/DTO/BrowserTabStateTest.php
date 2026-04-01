<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\BrowserTabState;

describe('BrowserTabState', function () {
    it('constructs with required fields', function () {
        $tab = new BrowserTabState(
            tabId: 'tab1',
            url: 'https://example.com',
            title: 'Example',
            isActive: true,
        );

        expect($tab->tabId)->toBe('tab1')
            ->and($tab->url)->toBe('https://example.com')
            ->and($tab->title)->toBe('Example')
            ->and($tab->isActive)->toBeTrue();
    });

    it('creates from array', function () {
        $tab = BrowserTabState::fromArray([
            'tab_id' => 'tab2',
            'url' => 'https://example.com/page',
            'title' => 'Page',
            'is_active' => false,
        ]);

        expect($tab->tabId)->toBe('tab2')
            ->and($tab->isActive)->toBeFalse();
    });

    it('defaults title and isActive from array', function () {
        $tab = BrowserTabState::fromArray([
            'tab_id' => 'tab3',
            'url' => 'https://example.com',
        ]);

        expect($tab->title)->toBe('')
            ->and($tab->isActive)->toBeFalse();
    });

    it('converts to array', function () {
        $tab = new BrowserTabState('tab1', 'https://example.com', 'Example', true);
        $array = $tab->toArray();

        expect($array)->toBe([
            'tab_id' => 'tab1',
            'url' => 'https://example.com',
            'title' => 'Example',
            'is_active' => true,
        ]);
    });

    it('round-trips through fromArray and toArray', function () {
        $original = [
            'tab_id' => 'tab1',
            'url' => 'https://example.com',
            'title' => 'Example',
            'is_active' => true,
        ];

        expect(BrowserTabState::fromArray($original)->toArray())->toBe($original);
    });
});
