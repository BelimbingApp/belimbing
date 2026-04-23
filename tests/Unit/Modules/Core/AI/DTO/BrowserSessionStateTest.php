<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\BrowserSessionState;
use App\Modules\Core\AI\DTO\BrowserTabState;
use App\Modules\Core\AI\Enums\BrowserSessionStatus;

const BROWSER_SESSION_URL = 'https://example.com';
const BROWSER_SESSION_CREATED_AT = '2026-01-01T00:00:00+00:00';
const BROWSER_SESSION_LAST_ACTIVITY_AT = '2026-01-01T00:01:00+00:00';

describe('BrowserSessionState', function () {
    it('constructs with all fields', function () {
        $tab = new BrowserTabState('tab1', BROWSER_SESSION_URL, 'Example', true);

        $state = new BrowserSessionState(
            sessionId: 'bs_test',
            agentEmployeeId: 1,
            actingForUserId: 10,
            companyId: 2,
            status: BrowserSessionStatus::Ready,
            headless: false,
            activeTabId: 'tab1',
            currentUrl: BROWSER_SESSION_URL,
            tabs: [$tab],
            lastSnapshotRef: BROWSER_SESSION_CREATED_AT,
            failureReason: null,
            createdAt: BROWSER_SESSION_CREATED_AT,
            lastActivityAt: BROWSER_SESSION_LAST_ACTIVITY_AT,
            expiresAt: '2026-01-01T00:06:00+00:00',
        );

        expect($state->sessionId)->toBe('bs_test')
            ->and($state->status)->toBe(BrowserSessionStatus::Ready)
            ->and($state->headless)->toBeFalse()
            ->and($state->tabs)->toHaveCount(1)
            ->and($state->tabs[0]->tabId)->toBe('tab1');
    });

    it('converts to array', function () {
        $state = new BrowserSessionState(
            sessionId: 'bs_arr',
            agentEmployeeId: 1,
            actingForUserId: null,
            companyId: 2,
            status: BrowserSessionStatus::Busy,
            headless: true,
            activeTabId: null,
            currentUrl: null,
            tabs: [],
            lastSnapshotRef: null,
            failureReason: null,
            createdAt: BROWSER_SESSION_CREATED_AT,
            lastActivityAt: BROWSER_SESSION_LAST_ACTIVITY_AT,
            expiresAt: null,
        );

        $array = $state->toArray();

        expect($array['session_id'])->toBe('bs_arr')
            ->and($array['agent_employee_id'])->toBe(1)
            ->and($array['acting_for_user_id'])->toBeNull()
            ->and($array['status'])->toBe('busy')
            ->and($array['headless'])->toBeTrue()
            ->and($array['tabs'])->toBe([])
            ->and($array['expires_at'])->toBeNull();
    });

    it('serializes tabs correctly', function () {
        $tabs = [
            new BrowserTabState('t1', 'https://a.com', 'A', true),
            new BrowserTabState('t2', 'https://b.com', 'B', false),
        ];

        $state = new BrowserSessionState(
            sessionId: 'bs_tabs',
            agentEmployeeId: 1,
            actingForUserId: null,
            companyId: 1,
            status: BrowserSessionStatus::Ready,
            headless: true,
            activeTabId: 't1',
            currentUrl: 'https://a.com',
            tabs: $tabs,
            lastSnapshotRef: null,
            failureReason: null,
            createdAt: BROWSER_SESSION_CREATED_AT,
            lastActivityAt: BROWSER_SESSION_LAST_ACTIVITY_AT,
            expiresAt: null,
        );

        $array = $state->toArray();

        expect($array['tabs'])->toHaveCount(2)
            ->and($array['tabs'][0]['tab_id'])->toBe('t1')
            ->and($array['tabs'][1]['tab_id'])->toBe('t2');
    });
});
