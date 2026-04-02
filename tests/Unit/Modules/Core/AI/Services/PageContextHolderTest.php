<?php

use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use App\Modules\Core\AI\Services\PageContextHolder;

const HOLDER_TEST_ROUTE = 'admin.employees.show';
const HOLDER_TEST_URL = 'http://localhost/admin/employees/1';

describe('PageContextHolder', function () {
    it('starts with no context and page consent level', function () {
        $holder = new PageContextHolder;

        expect($holder->getContext())->toBeNull()
            ->and($holder->getSnapshot())->toBeNull()
            ->and($holder->getConsentLevel())->toBe('page')
            ->and($holder->hasContext())->toBeFalse()
            ->and($holder->hasSnapshot())->toBeFalse();
    });

    it('stores and retrieves context', function () {
        $holder = new PageContextHolder;
        $ctx = new PageContext(route: HOLDER_TEST_ROUTE, url: HOLDER_TEST_URL);

        $holder->setContext($ctx);

        expect($holder->getContext())->toBe($ctx)
            ->and($holder->hasContext())->toBeTrue();
    });

    it('reports no context when consent is off', function () {
        $holder = new PageContextHolder;
        $holder->setContext(new PageContext(route: HOLDER_TEST_ROUTE, url: HOLDER_TEST_URL));
        $holder->setConsentLevel('off');

        expect($holder->hasContext())->toBeFalse()
            ->and($holder->getContext())->not->toBeNull(); // still stored, just not "available"
    });

    it('reports snapshot only when consent is full', function () {
        $holder = new PageContextHolder;
        $ctx = new PageContext(route: HOLDER_TEST_ROUTE, url: HOLDER_TEST_URL);
        $holder->setContext($ctx);
        $holder->setSnapshot(new PageSnapshot(pageContext: $ctx));

        // Default consent is 'page' → no snapshot
        expect($holder->hasSnapshot())->toBeFalse();

        $holder->setConsentLevel('full');
        expect($holder->hasSnapshot())->toBeTrue();
    });

    it('ignores invalid consent levels', function () {
        $holder = new PageContextHolder;
        $holder->setConsentLevel('invalid');

        expect($holder->getConsentLevel())->toBe('page');
    });

    it('accepts all three valid consent levels', function () {
        $holder = new PageContextHolder;

        foreach (['off', 'page', 'full'] as $level) {
            $holder->setConsentLevel($level);
            expect($holder->getConsentLevel())->toBe($level);
        }
    });
});
