<?php

use App\Modules\Core\AI\DTO\PageContext;

const PAGE_CONTEXT_TEST_ROUTE = 'admin.employees.show';
const PAGE_CONTEXT_TEST_URL = 'http://localhost/admin/employees/42';
const PAGE_CONTEXT_TEST_TITLE = 'Jane Doe';

describe('PageContext DTO', function () {
    it('serializes to array with only non-null values', function () {
        $context = new PageContext(
            route: PAGE_CONTEXT_TEST_ROUTE,
            url: PAGE_CONTEXT_TEST_URL,
            title: PAGE_CONTEXT_TEST_TITLE,
            module: 'Employee',
        );

        $array = $context->toArray();

        expect($array)->toBe([
            'route' => PAGE_CONTEXT_TEST_ROUTE,
            'url' => PAGE_CONTEXT_TEST_URL,
            'title' => PAGE_CONTEXT_TEST_TITLE,
            'module' => 'Employee',
        ]);
    });

    it('includes all populated fields in array', function () {
        $context = new PageContext(
            route: PAGE_CONTEXT_TEST_ROUTE,
            url: PAGE_CONTEXT_TEST_URL,
            title: PAGE_CONTEXT_TEST_TITLE,
            module: 'Employee',
            resourceType: 'employee',
            resourceId: 42,
            tabs: ['Details', 'Addresses'],
            activeTab: 'Details',
            visibleActions: ['Edit', 'Delete'],
            breadcrumbs: ['Employees', 'Jane Doe'],
            filters: ['status:active'],
            searchQuery: 'jane',
        );

        $array = $context->toArray();

        expect($array)
            ->toHaveKey('tabs', ['Details', 'Addresses'])
            ->toHaveKey('active_tab', 'Details')
            ->toHaveKey('visible_actions', ['Edit', 'Delete'])
            ->toHaveKey('breadcrumbs', ['Employees', 'Jane Doe'])
            ->toHaveKey('filters', ['status:active'])
            ->toHaveKey('search_query', 'jane');
    });

    it('renders compact XML for system prompt', function () {
        $context = new PageContext(
            route: PAGE_CONTEXT_TEST_ROUTE,
            url: PAGE_CONTEXT_TEST_URL,
            title: PAGE_CONTEXT_TEST_TITLE,
            module: 'Employee',
            resourceType: 'employee',
            resourceId: 42,
        );

        $xml = $context->toPromptXml();

        expect($xml)
            ->toStartWith('<current_page ')
            ->toEndWith('/>')
            ->toContain('route="admin.employees.show"')
            ->toContain('title="Jane Doe"')
            ->toContain('module="Employee"')
            ->toContain('resource_type="employee"')
            ->toContain('resource_id="42"');
    });

    it('escapes XML special characters', function () {
        $context = new PageContext(
            route: 'admin.test',
            url: 'http://localhost/test',
            title: 'Role "Admin" & <System>',
        );

        $xml = $context->toPromptXml();

        expect($xml)
            ->toContain('title="Role &quot;Admin&quot; &amp; &lt;System&gt;"')
            ->not->toContain('title="Role "Admin"');
    });

    it('hydrates from array via fromArray()', function () {
        $original = new PageContext(
            route: PAGE_CONTEXT_TEST_ROUTE,
            url: PAGE_CONTEXT_TEST_URL,
            title: PAGE_CONTEXT_TEST_TITLE,
            module: 'Employee',
            resourceType: 'employee',
            resourceId: 42,
            visibleActions: ['Edit'],
        );

        $hydrated = PageContext::fromArray($original->toArray());

        expect($hydrated->route)->toBe($original->route)
            ->and($hydrated->url)->toBe($original->url)
            ->and($hydrated->title)->toBe($original->title)
            ->and($hydrated->module)->toBe($original->module)
            ->and($hydrated->resourceType)->toBe($original->resourceType)
            ->and($hydrated->resourceId)->toBe($original->resourceId)
            ->and($hydrated->visibleActions)->toBe($original->visibleActions);
    });

    it('omits empty arrays and null values from XML', function () {
        $context = new PageContext(
            route: 'admin.dashboard',
            url: 'http://localhost/admin',
        );

        $xml = $context->toPromptXml();

        expect($xml)
            ->toContain('route="admin.dashboard"')
            ->not->toContain('title=')
            ->not->toContain('module=')
            ->not->toContain('actions=');
    });
});
