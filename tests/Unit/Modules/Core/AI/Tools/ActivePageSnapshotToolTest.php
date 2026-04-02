<?php

use App\Modules\Core\AI\DTO\FormFieldSnapshot;
use App\Modules\Core\AI\DTO\FormSnapshot;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\AI\Tools\ActivePageSnapshotTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

const SNAP_TOOL_ROUTE = 'admin.employees.show';
const SNAP_TOOL_URL = 'http://localhost/admin/employees/1';

beforeEach(function () {
    $this->holder = new PageContextHolder;
    $this->tool = new ActivePageSnapshotTool($this->holder);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'active_page_snapshot',
            'ai.tool_active_page_snapshot.view',
            [],
            null,
        );
    });
});

describe('consent enforcement', function () {
    it('returns disabled message when consent is off', function () {
        $this->holder->setConsentLevel('off');

        $result = (string) $this->tool->execute([]);

        expect($result)->toContain('disabled by the user');
    });

    it('returns no-context message when no page is set', function () {
        $result = (string) $this->tool->execute([]);

        expect($result)->toContain('No page context is available');
    });

    it('returns basic info when page has context but no snapshot', function () {
        $ctx = new PageContext(route: SNAP_TOOL_ROUTE, url: SNAP_TOOL_URL, title: 'Test');
        $this->holder->setContext($ctx);

        $result = (string) $this->tool->execute([]);

        expect($result)
            ->toContain('does not provide a detailed snapshot')
            ->toContain('admin.employees.show');
    });
});

describe('snapshot output', function () {
    it('returns JSON snapshot when consent is full and snapshot is available', function () {
        $ctx = new PageContext(route: SNAP_TOOL_ROUTE, url: SNAP_TOOL_URL, title: 'Test');
        $snapshot = new PageSnapshot(
            pageContext: $ctx,
            forms: [new FormSnapshot('form-1', fields: [
                new FormFieldSnapshot('name', 'string', 'Alice'),
            ])],
        );

        $this->holder->setConsentLevel('full');
        $this->holder->setContext($ctx);
        $this->holder->setSnapshot($snapshot);

        $result = (string) $this->tool->execute([]);
        $data = json_decode($result, true);

        expect($data)->toBeArray()
            ->and($data['page']['route'])->toBe(SNAP_TOOL_ROUTE)
            ->and($data['forms'][0]['id'])->toBe('form-1')
            ->and($data['forms'][0]['fields'][0]['name'])->toBe('name');
    });
});
