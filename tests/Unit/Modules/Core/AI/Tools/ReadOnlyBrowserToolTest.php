<?php

use App\Modules\Core\AI\Services\Browser\BrowserArtifactStore;
use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use App\Modules\Core\AI\Services\Browser\BrowserSsrfGuard;
use App\Modules\Core\AI\Tools\ReadOnlyBrowserTool;
use Tests\Support\BrowserToolTestCase;

uses(BrowserToolTestCase::class);

beforeEach(function (): void {
    $this->sessionManager = Mockery::mock(BrowserSessionManager::class);
    $this->ssrfGuard = Mockery::mock(BrowserSsrfGuard::class);
    $this->artifactStore = Mockery::mock(BrowserArtifactStore::class);
    $this->tool = new ReadOnlyBrowserTool(
        $this->sessionManager,
        $this->ssrfGuard,
        $this->artifactStore,
    );
});

it('advertises only navigation and inspection actions', function (): void {
    $actions = $this->tool->parametersSchema()['properties']['action']['enum'];

    expect($this->tool->name())->toBe('browser_read_only')
        ->and($actions)->toBe([
            'navigate',
            'snapshot',
            'screenshot',
            'tabs',
            'open',
            'close',
            'wait',
        ])
        ->and($actions)->not->toContain('act', 'evaluate', 'cookies', 'pdf')
        ->and(array_keys($this->tool->parametersSchema()['properties']))
        ->not->toContain('kind', 'submit', 'script', 'cookie_action', 'cookie_value');
});

it('rejects every state-changing browser action before opening a session', function (string $action): void {
    $this->assertToolError(['action' => $action], 'must be one of');
})->with(['act', 'evaluate', 'cookies', 'pdf']);
