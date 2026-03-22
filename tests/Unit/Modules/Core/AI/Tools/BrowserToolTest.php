<?php

use App\Modules\Core\AI\Services\Browser\BrowserPoolManager;
use App\Modules\Core\AI\Services\Browser\BrowserSsrfGuard;
use App\Modules\Core\AI\Services\Browser\PlaywrightRunner;
use App\Modules\Core\AI\Tools\BrowserTool;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const BROWSER_EXAMPLE_URL = 'https://example.com';

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
 * Build a session_required error for actions that need a persistent browser.
 */
function runnerSessionRequired(string $action): array
{
    return runnerError(
        $action,
        "The \"{$action}\" action requires an active browser session. "
            .'Per-command execution does not support session-dependent actions yet.',
        'session_required',
    );
}

beforeEach(function () {
    $this->poolManager = Mockery::mock(BrowserPoolManager::class);
    $this->ssrfGuard = Mockery::mock(BrowserSsrfGuard::class);
    $this->runner = Mockery::mock(PlaywrightRunner::class);
    $this->tool = new BrowserTool($this->poolManager, $this->ssrfGuard, $this->runner);

    $this->poolManager->shouldReceive('isAvailable')->andReturn(true)->byDefault();
    $this->ssrfGuard->shouldReceive('validate')->andReturn(true)->byDefault();
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

    it('returns error when pool unavailable', function () {
        $this->poolManager->shouldReceive('isAvailable')->andReturn(false);

        $result = $this->tool->execute(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL]);

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

        $result = $this->tool->execute(['action' => 'navigate', 'url' => 'https://evil.internal']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Blocked');
    });

    it('navigates successfully via runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('navigate', ['url' => BROWSER_EXAMPLE_URL])
            ->andReturn(runnerSuccess('navigate', [
                'url' => BROWSER_EXAMPLE_URL,
                'title' => 'Example Domain',
                'status' => 'navigated',
                'httpStatus' => 200,
            ]));

        $data = $this->decodeToolExecution(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL]);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('navigated')
            ->and($data['title'])->toBe('Example Domain');
    });

    it('returns error when runner fails', function () {
        $this->runner->shouldReceive('execute')
            ->andThrow(new RuntimeException('Browser process timed out'));

        $result = $this->tool->execute(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Browser action failed');
    });
});

describe('snapshot action', function () {
    it('returns snapshot with default format', function () {
        $this->runner->shouldReceive('execute')
            ->with('snapshot', Mockery::on(fn ($args) => $args['format'] === 'ai'))
            ->andReturn(runnerSuccess('snapshot', [
                'format' => 'ai',
                'content' => 'Example Domain',
                'status' => 'captured',
            ]));

        $data = $this->decodeToolExecution(['action' => 'snapshot']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('captured')
            ->and($data['format'])->toBe('ai');
    });

    it('accepts aria format', function () {
        $this->runner->shouldReceive('execute')
            ->with('snapshot', Mockery::on(fn ($args) => $args['format'] === 'aria'))
            ->andReturn(runnerSuccess('snapshot', [
                'format' => 'aria',
                'content' => '- heading "Example Domain"',
                'status' => 'captured',
            ]));

        $data = $this->decodeToolExecution(['action' => 'snapshot', 'format' => 'aria']);

        expect($data['format'])->toBe('aria');
    });
});

describe('screenshot action', function () {
    it('returns screenshot via runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('screenshot', Mockery::type('array'))
            ->andReturn(runnerSuccess('screenshot', [
                'image_base64' => 'iVBORw0KGgo=',
                'status' => 'captured',
            ]));

        $data = $this->decodeToolExecution(['action' => 'screenshot']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('captured');
    });

    it('passes full_page flag to runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('screenshot', Mockery::on(fn ($args) => $args['full_page'] === true))
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
        $result = $this->tool->execute(['action' => 'act', 'kind' => 'bogus']);
        expect((string) $result)->toContain('Error');
    });

    it('rejects missing ref', function () {
        $this->assertToolError(['action' => 'act', 'kind' => 'click'], 'ref');
    });

    it('returns session_required error for act', function () {
        $this->runner->shouldReceive('execute')
            ->with('act', Mockery::type('array'))
            ->andReturn(runnerSessionRequired('act'));

        $result = $this->tool->execute(['action' => 'act', 'kind' => 'click', 'ref' => 'e1']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('session');
    });
});

describe('tabs action', function () {
    it('returns session_required error for tabs', function () {
        $this->runner->shouldReceive('execute')
            ->with('tabs', [])
            ->andReturn(runnerSessionRequired('tabs'));

        $result = $this->tool->execute(['action' => 'tabs']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('session');
    });
});

describe('open action', function () {
    it('rejects missing url', function () {
        $this->assertToolError(['action' => 'open']);
    });

    it('returns session_required error for open', function () {
        $this->runner->shouldReceive('execute')
            ->with('open', ['url' => BROWSER_EXAMPLE_URL])
            ->andReturn(runnerSessionRequired('open'));

        $result = $this->tool->execute(['action' => 'open', 'url' => BROWSER_EXAMPLE_URL]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('session');
    });
});

describe('close action', function () {
    it('rejects missing tab_id', function () {
        $this->assertToolError(['action' => 'close']);
    });

    it('returns session_required error for close', function () {
        $this->runner->shouldReceive('execute')
            ->with('close', ['tab_id' => 'tab1'])
            ->andReturn(runnerSessionRequired('close'));

        $result = $this->tool->execute(['action' => 'close', 'tab_id' => 'tab1']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('session');
    });
});

describe('evaluate action', function () {
    it('rejects when evaluate disabled', function () {
        config()->set('ai.tools.browser.evaluate_enabled', false);

        $result = $this->tool->execute(['action' => 'evaluate', 'script' => 'alert(1)']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('disabled');
    });

    it('rejects missing script when enabled', function () {
        config()->set('ai.tools.browser.evaluate_enabled', true);

        $result = $this->tool->execute(['action' => 'evaluate']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('script');
    });

    it('evaluates via runner when enabled', function () {
        config()->set('ai.tools.browser.evaluate_enabled', true);

        $this->runner->shouldReceive('execute')
            ->with('evaluate', ['script' => 'document.title'])
            ->andReturn(runnerSuccess('evaluate', [
                'result' => 'Example Domain',
                'status' => 'evaluated',
            ]));

        $data = $this->decodeToolExecution(['action' => 'evaluate', 'script' => 'document.title']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('evaluated')
            ->and($data['result'])->toBe('Example Domain');
    });
});

describe('pdf action', function () {
    it('exports pdf via runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('pdf', Mockery::type('array'))
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
        $result = $this->tool->execute(['action' => 'cookies']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('cookie_action');
    });

    it('rejects invalid cookie_action', function () {
        $result = $this->tool->execute(['action' => 'cookies', 'cookie_action' => 'bogus']);
        expect((string) $result)->toContain('Error');
    });

    it('gets cookies via runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('cookies', Mockery::on(fn ($args) => $args['cookie_action'] === 'get'))
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
        $result = $this->tool->execute(['action' => 'cookies', 'cookie_action' => 'set']);
        expect((string) $result)->toContain('Error');
    });

    it('sets cookie via runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('cookies', Mockery::on(fn ($args) => $args['cookie_action'] === 'set'
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

    it('clears cookies via runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('cookies', Mockery::on(fn ($args) => $args['cookie_action'] === 'clear'))
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
        $result = $this->tool->execute(['action' => 'wait']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('At least one');
    });

    it('waits for text condition via runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('wait', Mockery::on(fn ($args) => $args['text'] === 'Hello'))
            ->andReturn(runnerSuccess('wait', [
                'text' => 'Hello',
                'status' => 'matched',
            ]));

        $data = $this->decodeToolExecution(['action' => 'wait', 'text' => 'Hello']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('matched');
    });

    it('waits for selector condition via runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('wait', Mockery::on(fn ($args) => $args['selector'] === '#main'))
            ->andReturn(runnerSuccess('wait', [
                'selector' => '#main',
                'status' => 'matched',
            ]));

        $data = $this->decodeToolExecution(['action' => 'wait', 'selector' => '#main']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('matched');
    });

    it('waits for url condition via runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('wait', Mockery::on(fn ($args) => $args['url'] === 'https://example.com/done'))
            ->andReturn(runnerSuccess('wait', [
                'url' => 'https://example.com/done',
                'status' => 'matched',
            ]));

        $data = $this->decodeToolExecution(['action' => 'wait', 'url' => 'https://example.com/done']);

        expect($data['ok'])->toBeTrue()
            ->and($data['status'])->toBe('matched');
    });

    it('passes timeout to runner', function () {
        $this->runner->shouldReceive('execute')
            ->with('wait', Mockery::on(fn ($args) => $args['timeout_ms'] === 10000))
            ->andReturn(runnerSuccess('wait', ['status' => 'matched']));

        $data = $this->decodeToolExecution(['action' => 'wait', 'text' => 'Hi', 'timeout_ms' => 10000]);

        expect($data['ok'])->toBeTrue();
    });

    it('uses default timeout', function () {
        $this->runner->shouldReceive('execute')
            ->with('wait', Mockery::on(fn ($args) => $args['timeout_ms'] === 5000))
            ->andReturn(runnerSuccess('wait', ['status' => 'matched']));

        $data = $this->decodeToolExecution(['action' => 'wait', 'text' => 'Hello']);

        expect($data['ok'])->toBeTrue();
    });
});

describe('runner error handling', function () {
    it('converts RuntimeException to error result', function () {
        $this->runner->shouldReceive('execute')
            ->andThrow(new RuntimeException('Process timed out after 30 seconds'));

        $result = $this->tool->execute(['action' => 'snapshot']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Browser action failed')
            ->and((string) $result)->toContain('Process timed out');
    });

    it('converts runner error response to error result', function () {
        $this->runner->shouldReceive('execute')
            ->andReturn(runnerError('navigate', 'net::ERR_NAME_NOT_RESOLVED', 'action_failed'));

        $result = $this->tool->execute(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('net::ERR_NAME_NOT_RESOLVED');
    });
});

describe('per-call headless override', function () {
    it('forwards headless=true to runner when explicitly set', function () {
        $this->runner->shouldReceive('execute')
            ->with('snapshot', Mockery::on(fn ($args) => $args['headless'] === true && $args['format'] === 'ai'))
            ->andReturn(runnerSuccess('snapshot', ['format' => 'ai', 'status' => 'captured']));

        $data = $this->decodeToolExecution(['action' => 'snapshot', 'headless' => true]);

        expect($data['ok'])->toBeTrue();
    });

    it('forwards headless=false to runner when explicitly set', function () {
        $this->runner->shouldReceive('execute')
            ->with('navigate', Mockery::on(fn ($args) => $args['headless'] === false && $args['url'] === BROWSER_EXAMPLE_URL))
            ->andReturn(runnerSuccess('navigate', ['url' => BROWSER_EXAMPLE_URL, 'status' => 'navigated']));

        $data = $this->decodeToolExecution(['action' => 'navigate', 'url' => BROWSER_EXAMPLE_URL, 'headless' => false]);

        expect($data['ok'])->toBeTrue();
    });

    it('does not inject headless key when not provided in input', function () {
        $this->runner->shouldReceive('execute')
            ->with('snapshot', Mockery::on(fn ($args) => ! array_key_exists('headless', $args)))
            ->andReturn(runnerSuccess('snapshot', ['format' => 'ai', 'status' => 'captured']));

        $data = $this->decodeToolExecution(['action' => 'snapshot']);

        expect($data['ok'])->toBeTrue();
    });
});
