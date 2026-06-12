<?php

use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use App\Modules\Core\AI\Services\PageContextHolder;

const HOLDER_TEST_ROUTE = 'admin.employees.show';
const HOLDER_TEST_URL = 'http://localhost/admin/employees/1';

describe('PageContextHolder', function () {
    it('starts with no context or snapshot', function () {
        $holder = new PageContextHolder;

        expect($holder->getContext())->toBeNull()
            ->and($holder->getSnapshot())->toBeNull()
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

    it('reports snapshot when a snapshot is stored', function () {
        $holder = new PageContextHolder;
        $ctx = new PageContext(route: HOLDER_TEST_ROUTE, url: HOLDER_TEST_URL);
        $holder->setContext($ctx);
        $holder->setSnapshot(new PageSnapshot(pageContext: $ctx));

        expect($holder->hasSnapshot())->toBeTrue();
    });
});
