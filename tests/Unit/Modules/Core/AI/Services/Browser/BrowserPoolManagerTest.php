<?php

use App\Modules\Core\AI\Services\Browser\BrowserContextFactory;
use App\Modules\Core\AI\Services\Browser\BrowserPoolManager;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->factory = Mockery::mock(BrowserContextFactory::class);
    $this->manager = new BrowserPoolManager($this->factory);
});

describe('isAvailable', function (): void {
    it('returns false when browser disabled', function (): void {
        config()->set('ai.tools.browser.enabled', false);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);

        expect($this->manager->isAvailable())->toBeFalse();
    });

    it('returns false when factory unavailable', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        $this->factory->shouldReceive('isAvailable')->andReturn(false);

        expect($this->manager->isAvailable())->toBeFalse();
    });

    it('returns true when enabled and factory available', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);

        expect($this->manager->isAvailable())->toBeTrue();
    });
});

describe('acquireContext', function (): void {
    it('returns false when disabled', function (): void {
        config()->set('ai.tools.browser.enabled', false);

        expect($this->manager->acquireContext(1, 'sess1'))->toBeFalse();
    });

    it('returns false when factory unavailable', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        $this->factory->shouldReceive('isAvailable')->andReturn(false);

        expect($this->manager->acquireContext(1, 'sess1'))->toBeFalse();
    });

    it('acquires context successfully', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);
        $this->factory->shouldReceive('createContextId')->with(1, 'sess1')->andReturn('ctx_1_sess1');

        expect($this->manager->acquireContext(1, 'sess1'))->toBe('ctx_1_sess1');
    });

    it('returns existing context for same company+session', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);
        $this->factory->shouldReceive('createContextId')->with(1, 'sess1')->once()->andReturn('ctx_1_sess1');

        $first = $this->manager->acquireContext(1, 'sess1');
        $second = $this->manager->acquireContext(1, 'sess1');

        expect($first)->toBe('ctx_1_sess1')
            ->and($second)->toBe('ctx_1_sess1');
    });

    it('enforces per-company limit', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        config()->set('ai.tools.browser.max_contexts_per_company', 2);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);
        $this->factory->shouldReceive('createContextId')->with(1, 'sess1')->andReturn('ctx_1_sess1');
        $this->factory->shouldReceive('createContextId')->with(1, 'sess2')->andReturn('ctx_1_sess2');

        $this->manager->acquireContext(1, 'sess1');
        $this->manager->acquireContext(1, 'sess2');

        expect($this->manager->acquireContext(1, 'sess3'))->toBeFalse();
    });

    it('allows different companies to have their own contexts', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        config()->set('ai.tools.browser.max_contexts_per_company', 1);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);
        $this->factory->shouldReceive('createContextId')->with(1, 'sess1')->andReturn('ctx_1_sess1');
        $this->factory->shouldReceive('createContextId')->with(2, 'sess1')->andReturn('ctx_2_sess1');

        expect($this->manager->acquireContext(1, 'sess1'))->toBe('ctx_1_sess1')
            ->and($this->manager->acquireContext(2, 'sess1'))->toBe('ctx_2_sess1');
    });
});

describe('releaseContext', function (): void {
    it('releases context', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);
        $this->factory->shouldReceive('createContextId')->with(1, 'sess1')->andReturn('ctx_1_sess1');

        $contextId = $this->manager->acquireContext(1, 'sess1');
        $this->manager->releaseContext($contextId);

        expect($this->manager->hasContext($contextId))->toBeFalse();
    });

    it('frees slot for new context', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        config()->set('ai.tools.browser.max_contexts_per_company', 1);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);
        $this->factory->shouldReceive('createContextId')->with(1, 'sess1')->andReturn('ctx_1_sess1');
        $this->factory->shouldReceive('createContextId')->with(1, 'sess2')->andReturn('ctx_1_sess2');

        $contextId = $this->manager->acquireContext(1, 'sess1');
        $this->manager->releaseContext($contextId);

        expect($this->manager->acquireContext(1, 'sess2'))->toBe('ctx_1_sess2');
    });
});

describe('hasContext', function (): void {
    it('returns false for unknown context', function (): void {
        expect($this->manager->hasContext('nonexistent'))->toBeFalse();
    });

    it('returns true for active context', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);
        $this->factory->shouldReceive('createContextId')->with(1, 'sess1')->andReturn('ctx_1_sess1');

        $contextId = $this->manager->acquireContext(1, 'sess1');

        expect($this->manager->hasContext($contextId))->toBeTrue();
    });
});

describe('getActiveContextCount', function (): void {
    it('returns 0 for company with no contexts', function (): void {
        expect($this->manager->getActiveContextCount(99))->toBe(0);
    });

    it('counts correctly', function (): void {
        config()->set('ai.tools.browser.enabled', true);
        $this->factory->shouldReceive('isAvailable')->andReturn(true);
        $this->factory->shouldReceive('createContextId')->with(1, 'sess1')->andReturn('ctx_1_sess1');
        $this->factory->shouldReceive('createContextId')->with(1, 'sess2')->andReturn('ctx_1_sess2');

        $this->manager->acquireContext(1, 'sess1');
        $this->manager->acquireContext(1, 'sess2');

        expect($this->manager->getActiveContextCount(1))->toBe(2);
    });
});
