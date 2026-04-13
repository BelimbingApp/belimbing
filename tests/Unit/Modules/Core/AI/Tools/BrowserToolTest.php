<?php

use App\Modules\Core\AI\DTO\BrowserArtifactMeta;
use App\Modules\Core\AI\Enums\BrowserArtifactType;
use App\Modules\Core\AI\Enums\BrowserSessionStatus;
use App\Modules\Core\AI\Models\BrowserSession;
use App\Modules\Core\AI\Services\Browser\BrowserArtifactStore;
use App\Modules\Core\AI\Services\Browser\BrowserSessionException;
use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use App\Modules\Core\AI\Services\Browser\BrowserSsrfGuard;
use App\Modules\Core\AI\Tools\BrowserTool;
use Tests\Support\BrowserToolTestCase;

uses(BrowserToolTestCase::class);

const BROWSER_EXAMPLE_URL = 'https://example.com';
const BROWSER_EXAMPLE_DOMAIN_TITLE = 'Example Domain';
const BROWSER_WAIT_SELECTOR_MAIN = '#main';
const BROWSER_WAIT_URL_DONE = 'https://example.com/done';

/**
 * Build a runner result array matching the Node.js runner format.
 *
 * @param  array<string, mixed>  $extra  Additional payload fields
 * @return array{ok: bool, action: string, ...}
 */
function runnerSuccess(string $action, array $extra = []): array
{
    return ['ok' => true, 'action' => $action, ...$extra];
}

/**
 * Build a runner error result matching the Node.js runner format.
 */
function runnerError(string $action, string $message, string $code = 'browser_error'): array
{
    return ['ok' => false, 'action' => $action, 'error' => $code, 'message' => $message];
}

/**
 * Create a fake BrowserSession model with given attributes.
 */
function fakeBrowserSession(array $attrs = []): BrowserSession
{
    $session = new BrowserSession;
    $session->id = $attrs['id'] ?? 'bs_test123';
    $session->employee_id = $attrs['employee_id'] ?? 0;
    $session->company_id = $attrs['company_id'] ?? 0;
    $session->status = $attrs['status'] ?? BrowserSessionStatus::Ready;
    $session->headless = $attrs['headless'] ?? true;

    return $session;
}

beforeEach(function () {
    $this->sessionManager = Mockery::mock(BrowserSessionManager::class);
    $this->ssrfGuard = Mockery::mock(BrowserSsrfGuard::class);
    $this->artifactStore = Mockery::mock(BrowserArtifactStore::class);
    $this->tool = new BrowserTool($this->sessionManager, $this->ssrfGuard, $this->artifactStore);

    $this->sessionManager->shouldReceive('isAvailable')->andReturn(true)->byDefault();
    $this->sessionManager->shouldReceive('open')->andReturn(fakeBrowserSession())->byDefault();
    $this->ssrfGuard->shouldReceive('validate')->andReturn(true)->byDefault();
    $this->artifactStore->shouldReceive('store')->andReturn(new BrowserArtifactMeta(
        artifactId: 'ba_test',
        sessionId: 'bs_test123',
        type: BrowserArtifactType::Snapshot,
        storagePath: 'browser-artifacts/bs_test123/ba_test.txt',
        mimeType: 'text/plain',
        sizeBytes: 0,
        relatedUrl: null,
        relatedTabId: null,
        createdAt: '2026-01-01T00:00:00+00:00',
    ))->byDefault();
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'browser',
            'ai.tool_browser.execute',
            ['action'],
            ['action'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing action', function () {
        $this->assertToolError([]);
    });

    it('rejects invalid action', function () {
        $this->assertToolError(['action' => 'bogus'], 'must be one of');
    });

    it('returns error when session manager unavailable', function () {
        $this->sessionManager->shouldReceive('isAvailable')->andReturn(false);

        $result = $this->executeBrowserTool(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL]);

        expect((string) $result)->toContain('not available');
    });
});

describe('navigate action', function () {
    it('rejects missing url', function () {
        $this->assertToolError(['action' => 'navigate'], 'url');
    });

    it('rejects SSRF blocked url', function () {
        $this->ssrfGuard->shouldReceive('validate')
            ->with('https://evil.internal')
            ->andReturn('Blocked: private');

        $result = $this->executeBrowserTool(['action' => 'navigate', 'url' => 'https://evil.internal']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Blocked');
    });

    it('navigates successfully via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'navigate', ['url' => BROWSER_EXAMPLE_URL])
            ->andReturn(runnerSuccess('navigate', [
                'url' => BROWSER_EXAMPLE_URL,
                'title' => BROWSER_EXAMPLE_DOMAIN_TITLE,
                'status' => 'navigated',
                'httpStatus' => 200,
            ]));

        $data = $this->decodeToolExecution(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL]);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('navigated')
            ->and($data['title'])->toBe(BROWSER_EXAMPLE_DOMAIN_TITLE);
    });

    it('returns error when session action fails', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->andThrow(new RuntimeException('Browser process timed out'));

        $result = $this->executeBrowserTool(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Browser action failed');
    });
});

describe('snapshot action', function () {
    it('returns snapshot with default format', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'snapshot', Mockery::on(fn ($args) => $args['format'] === 'ai'))
            ->andReturn(runnerSuccess('snapshot', [
                'format' => 'ai',
                'content' => BROWSER_EXAMPLE_DOMAIN_TITLE,
                'status' => 'captured',
            ]));

        $data = $this->decodeToolExecution(['action' => 'snapshot']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('captured')
            ->and($data['format'])->toBe('ai');
    });

    it('accepts aria format', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'snapshot', Mockery::on(fn ($args) => $args['format'] === 'aria'))
            ->andReturn(runnerSuccess('snapshot', [
                'format' => 'aria',
                'content' => '- heading "'.BROWSER_EXAMPLE_DOMAIN_TITLE.'"',
                'status' => 'captured',
            ]));

        $data = $this->decodeToolExecution(['action' => 'snapshot', 'format' => 'aria']);

        expect($data['format'])->toBe('aria');
    });
});

describe('screenshot action', function () {
    it('returns screenshot via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'screenshot', Mockery::type('array'))
            ->andReturn(runnerSuccess('screenshot', [
                'image_base64' => 'iVBORw0KGgo=',
                'status' => 'captured',
            ]));

        $data = $this->decodeToolExecution(['action' => 'screenshot']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('captured');
    });

    it('passes full_page flag', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'screenshot', Mockery::on(fn ($args) => $args['full_page'] === true))
            ->andReturn(runnerSuccess('screenshot', [
                'image_base64' => 'iVBORw0KGgo=',
                'full_page' => true,
                'status' => 'captured',
            ]));

        $data = $this->decodeToolExecution(['action' => 'screenshot', 'full_page' => true]);

        expect($data['full_page'])->toBeTrue();
    });
});

describe('act action', function () {
    it('rejects missing kind', function () {
        $this->assertToolError(['action' => 'act'], 'kind');
    });

    it('rejects invalid kind', function () {
        $result = $this->executeBrowserTool(['action' => 'act', 'kind' => 'bogus']);
        expect((string) $result)->toContain('Error');
    });

    it('rejects missing ref', function () {
        $this->assertToolError(['action' => 'act', 'kind' => 'click'], 'ref');
    });

    it('executes act via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'act', Mockery::type('array'))
            ->andReturn(runnerSuccess('act', ['status' => 'clicked']));

        $data = $this->decodeToolExecution(['action' => 'act', 'kind' => 'click', 'ref' => 'e1']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('clicked');
    });
});

describe('tabs action', function () {
    it('lists tabs via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'tabs', [])
            ->andReturn(runnerSuccess('tabs', ['tabs' => []]));

        $data = $this->decodeToolExecution(['action' => 'tabs']);

        expect($data['ok'])->toBeTrue();
    });
});

describe('open action', function () {
    it('rejects missing url', function () {
        $this->assertToolError(['action' => 'open']);
    });

    it('opens tab via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'open', ['url' => BROWSER_EXAMPLE_URL])
            ->andReturn(runnerSuccess('open', ['url' => BROWSER_EXAMPLE_URL, 'tab_id' => 'tab2']));

        $data = $this->decodeToolExecution(['action' => 'open', 'url' => BROWSER_EXAMPLE_URL]);

        expect($data['ok'])->toBeTrue()
            ->and($data['tab_id'])->toBe('tab2');
    });
});

describe('close action', function () {
    it('rejects missing tab_id', function () {
        $this->assertToolError(['action' => 'close']);
    });

    it('closes tab via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'close', ['tab_id' => 'tab1'])
            ->andReturn(runnerSuccess('close', ['status' => 'closed']));

        $data = $this->decodeToolExecution(['action' => 'close', 'tab_id' => 'tab1']);

        expect($data['ok'])->toBeTrue();
    });
});

describe('evaluate action', function () {
    it('rejects when evaluate disabled', function () {
        config()->set('ai.tools.browser.evaluate_enabled', false);

        $result = $this->executeBrowserTool(['action' => 'evaluate', 'script' => 'alert(1)']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('disabled');
    });

    it('rejects missing script when enabled', function () {
        config()->set('ai.tools.browser.evaluate_enabled', true);

        $result = $this->executeBrowserTool(['action' => 'evaluate']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('script');
    });

    it('evaluates via session manager when enabled', function () {
        config()->set('ai.tools.browser.evaluate_enabled', true);

        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'evaluate', ['script' => 'document.title'])
            ->andReturn(runnerSuccess('evaluate', [
                'result' => BROWSER_EXAMPLE_DOMAIN_TITLE,
                'status' => 'evaluated',
            ]));

        $data = $this->decodeToolExecution(['action' => 'evaluate', 'script' => 'document.title']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('evaluated')
            ->and($data['result'])->toBe(BROWSER_EXAMPLE_DOMAIN_TITLE);
    });
});

describe('pdf action', function () {
    it('exports pdf via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'pdf', Mockery::type('array'))
            ->andReturn(runnerSuccess('pdf', [
                'pdf_base64' => 'JVBERi0xLjQ=',
                'size_bytes' => 1024,
                'status' => 'exported',
            ]));

        $data = $this->decodeToolExecution(['action' => 'pdf']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('exported');
    });
});

describe('cookies action', function () {
    it('rejects missing cookie_action', function () {
        $result = $this->executeBrowserTool(['action' => 'cookies']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('cookie_action');
    });

    it('rejects invalid cookie_action', function () {
        $result = $this->executeBrowserTool(['action' => 'cookies', 'cookie_action' => 'bogus']);
        expect((string) $result)->toContain('Error');
    });

    it('gets cookies via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'cookies', Mockery::on(fn ($args) => $args['cookie_action'] === 'get'))
            ->andReturn(runnerSuccess('cookies', [
                'cookie_action' => 'get',
                'cookies' => [],
                'status' => 'retrieved',
            ]));

        $data = $this->decodeToolExecution(['action' => 'cookies', 'cookie_action' => 'get']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('retrieved');
    });

    it('rejects set without name', function () {
        $result = $this->executeBrowserTool(['action' => 'cookies', 'cookie_action' => 'set']);
        expect((string) $result)->toContain('Error');
    });

    it('sets cookie via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'cookies', Mockery::on(fn ($args) => $args['cookie_action'] === 'set'
                && $args['cookie_name'] === 'test'
                && $args['cookie_value'] === 'val'))
            ->andReturn(runnerSuccess('cookies', [
                'cookie_action' => 'set',
                'cookie_name' => 'test',
                'status' => 'set',
            ]));

        $data = $this->decodeToolExecution([
            'action' => 'cookies',
            'cookie_action' => 'set',
            'cookie_name' => 'test',
            'cookie_value' => 'val',
        ]);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('set');
    });

    it('clears cookies via session manager', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'cookies', Mockery::on(fn ($args) => $args['cookie_action'] === 'clear'))
            ->andReturn(runnerSuccess('cookies', [
                'cookie_action' => 'clear',
                'status' => 'cleared',
            ]));

        $data = $this->decodeToolExecution(['action' => 'cookies', 'cookie_action' => 'clear']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('cleared');
    });
});

describe('wait action', function () {
    it('rejects when no condition specified', function () {
        $result = $this->executeBrowserTool(['action' => 'wait']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('At least one');
    });

    it('waits for text condition', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'wait', Mockery::on(fn ($args) => $args['text'] === 'Hello'))
            ->andReturn(runnerSuccess('wait', [
                'text' => 'Hello',
                'status' => 'matched',
            ]));

        $data = $this->decodeToolExecution(['action' => 'wait', 'text' => 'Hello']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('matched');
    });

    it('waits for selector condition', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'wait', Mockery::on(fn ($args) => $args['selector'] === BROWSER_WAIT_SELECTOR_MAIN))
            ->andReturn(runnerSuccess('wait', [
                'selector' => BROWSER_WAIT_SELECTOR_MAIN,
                'status' => 'matched',
            ]));

        $data = $this->decodeToolExecution(['action' => 'wait', 'selector' => BROWSER_WAIT_SELECTOR_MAIN]);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('matched');
    });

    it('waits for url condition', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'wait', Mockery::on(fn ($args) => $args['url'] === BROWSER_WAIT_URL_DONE))
            ->andReturn(runnerSuccess('wait', [
                'url' => BROWSER_WAIT_URL_DONE,
                'status' => 'matched',
            ]));

        $data = $this->decodeToolExecution(['action' => 'wait', 'url' => BROWSER_WAIT_URL_DONE]);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('matched');
    });

    it('passes timeout', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'wait', Mockery::on(fn ($args) => $args['timeout_ms'] === 10000))
            ->andReturn(runnerSuccess('wait', ['status' => 'matched']));

        $data = $this->decodeToolExecution(['action' => 'wait', 'text' => 'Hi', 'timeout_ms' => 10000]);

        expect($data['ok'])->toBeTrue();
    });

    it('uses default timeout', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->with('bs_test123', 'wait', Mockery::on(fn ($args) => $args['timeout_ms'] === 5000))
            ->andReturn(runnerSuccess('wait', ['status' => 'matched']));

        $data = $this->decodeToolExecution(['action' => 'wait', 'text' => 'Hello']);

        expect($data['ok'])->toBeTrue();
    });
});

describe('error handling', function () {
    it('converts RuntimeException to error result', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->andThrow(new RuntimeException('Process timed out after 30 seconds'));

        $result = $this->executeBrowserTool(['action' => 'snapshot']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Browser action failed')
            ->and((string) $result)->toContain('Process timed out');
    });

    it('converts runner error response to error result', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->andReturn(runnerError('navigate', 'net::ERR_NAME_NOT_RESOLVED', 'action_failed'));

        $result = $this->executeBrowserTool(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('net::ERR_NAME_NOT_RESOLVED');
    });

    it('converts session exception to error result', function () {
        $this->sessionManager->shouldReceive('executeAction')
            ->andThrow(new BrowserSessionException('Session expired'));

        $result = $this->executeBrowserTool(['action' => 'snapshot']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Session expired');
    });

    it('handles session open failure', function () {
        $this->sessionManager->shouldReceive('open')
            ->andThrow(new BrowserSessionException('Concurrency limit reached'));

        $result = $this->executeBrowserTool(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Concurrency limit');
    });
});

describe('headless mode', function () {
    it('opens session with headless from config when not specified', function () {
        config()->set('ai.tools.browser.headless', true);

        $this->sessionManager->shouldReceive('open')
            ->with(BrowserToolTestCase::BROWSER_TOOL_TEST_EMPLOYEE_ID, BrowserToolTestCase::BROWSER_TOOL_TEST_COMPANY_ID, true)
            ->andReturn(fakeBrowserSession());

        $this->sessionManager->shouldReceive('executeAction')
            ->andReturn(runnerSuccess('snapshot', ['status' => 'captured']));

        $data = $this->decodeToolExecution(['action' => 'snapshot']);

        expect($data['ok'])->toBeTrue();
    });

    it('opens session with headless=false when explicitly provided', function () {
        $this->sessionManager->shouldReceive('open')
            ->with(BrowserToolTestCase::BROWSER_TOOL_TEST_EMPLOYEE_ID, BrowserToolTestCase::BROWSER_TOOL_TEST_COMPANY_ID, false)
            ->andReturn(fakeBrowserSession(['headless' => false]));

        $this->sessionManager->shouldReceive('executeAction')
            ->andReturn(runnerSuccess('navigate', ['url' => BROWSER_EXAMPLE_URL, 'status' => 'navigated']));

        $data = $this->decodeToolExecution(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL, 'headless' => false]);

        expect($data['ok'])->toBeTrue();
    });
});
