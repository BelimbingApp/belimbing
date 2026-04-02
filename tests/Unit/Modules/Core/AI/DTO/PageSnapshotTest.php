<?php

use App\Modules\Core\AI\DTO\FormFieldSnapshot;
use App\Modules\Core\AI\DTO\FormSnapshot;
use App\Modules\Core\AI\DTO\ModalSnapshot;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use App\Modules\Core\AI\DTO\TableSnapshot;

const SNAPSHOT_TEST_ROUTE = 'admin.employees.show';
const SNAPSHOT_TEST_URL = 'http://localhost/admin/employees/1';

describe('PageSnapshot DTO', function () {
    it('serializes to array with page context', function () {
        $ctx = new PageContext(route: SNAPSHOT_TEST_ROUTE, url: SNAPSHOT_TEST_URL, title: 'Test');
        $snapshot = new PageSnapshot(pageContext: $ctx);

        $array = $snapshot->toArray();

        expect($array)->toHaveKey('page')
            ->and($array['page'])->toHaveKey('route', SNAPSHOT_TEST_ROUTE);
    });

    it('includes forms, tables, and modals when present', function () {
        $ctx = new PageContext(route: SNAPSHOT_TEST_ROUTE, url: SNAPSHOT_TEST_URL);
        $snapshot = new PageSnapshot(
            pageContext: $ctx,
            forms: [new FormSnapshot('edit-form', fields: [
                new FormFieldSnapshot('name', 'string', 'Alice'),
            ])],
            tables: [new TableSnapshot('employee-list', ['Name', 'Status'], 50)],
            modals: [new ModalSnapshot('confirm-delete', 'Delete?', true)],
        );

        $array = $snapshot->toArray();

        expect($array)
            ->toHaveKey('forms')
            ->toHaveKey('tables')
            ->toHaveKey('modals')
            ->and($array['forms'][0])->toHaveKey('id', 'edit-form')
            ->and($array['forms'][0]['fields'][0])->toHaveKey('name', 'name')
            ->and($array['tables'][0])->toHaveKey('total_rows', 50)
            ->and($array['modals'][0])->toHaveKey('open', true);
    });

    it('round-trips through toArray/fromArray', function () {
        $ctx = new PageContext(
            route: SNAPSHOT_TEST_ROUTE,
            url: SNAPSHOT_TEST_URL,
            title: 'Employee',
            module: 'Employee',
        );
        $original = new PageSnapshot(
            pageContext: $ctx,
            forms: [new FormSnapshot('form-1', dirty: true, fields: [
                new FormFieldSnapshot('email', 'string', 'a@b.com'),
                new FormFieldSnapshot('password', 'string', null, masked: true),
            ])],
            tables: [new TableSnapshot('tbl', ['A', 'B'], 100, 2, 25, 'A', 'asc')],
            modals: [new ModalSnapshot('modal-1', 'Confirm', true)],
            focusedElement: 'email',
        );

        $hydrated = PageSnapshot::fromArray($original->toArray());

        expect($hydrated->pageContext->route)->toBe(SNAPSHOT_TEST_ROUTE)
            ->and($hydrated->forms)->toHaveCount(1)
            ->and($hydrated->forms[0]->id)->toBe('form-1')
            ->and($hydrated->forms[0]->dirty)->toBeTrue()
            ->and($hydrated->forms[0]->fields)->toHaveCount(2)
            ->and($hydrated->tables[0]->sortColumn)->toBe('A')
            ->and($hydrated->modals[0]->open)->toBeTrue()
            ->and($hydrated->focusedElement)->toBe('email');
    });
});

describe('FormFieldSnapshot DTO', function () {
    it('shows masked value as bullets', function () {
        $field = new FormFieldSnapshot('secret', 'string', 'hunter2', masked: true);

        $array = $field->toArray();

        expect($array['value'])->toBe('••••••')
            ->and($array['masked'])->toBeTrue();
    });

    it('shows plain value when not masked', function () {
        $field = new FormFieldSnapshot('name', 'string', 'Alice');

        $array = $field->toArray();

        expect($array['value'])->toBe('Alice')
            ->and($array)->not->toHaveKey('masked');
    });
});

describe('TableSnapshot DTO', function () {
    it('round-trips through fromArray', function () {
        $table = new TableSnapshot('tbl', ['Col1', 'Col2'], 100, 3, 20, 'Col1', 'desc');
        $hydrated = TableSnapshot::fromArray($table->toArray());

        expect($hydrated->id)->toBe('tbl')
            ->and($hydrated->columns)->toBe(['Col1', 'Col2'])
            ->and($hydrated->totalRows)->toBe(100)
            ->and($hydrated->currentPage)->toBe(3)
            ->and($hydrated->perPage)->toBe(20)
            ->and($hydrated->sortColumn)->toBe('Col1')
            ->and($hydrated->sortDirection)->toBe('desc');
    });
});
