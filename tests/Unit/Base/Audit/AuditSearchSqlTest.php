<?php

use App\Base\Audit\Services\AuditSearchSql;
use Tests\TestCase;

uses(TestCase::class);

it('builds portable audit SQL expressions for the default sqlite grammar', function (): void {
    config()->set('database.default', 'sqlite');

    $sql = new AuditSearchSql;

    expect($sql->lowerTextExpression('base_audit_mutations.subject_id'))->toBe('lower(cast(base_audit_mutations.subject_id as text))')
        ->and($sql->lowerCoalescedExpression('base_audit_actions.url'))->toBe('lower(coalesce(base_audit_actions.url, \'\'))')
        ->and($sql->lowerCoalescedLikeExpression('base_audit_actions.url'))->toBe('lower(coalesce(base_audit_actions.url, \'\')) like ?')
        ->and($sql->jsonTextExpression('base_audit_actions.payload'))->toBe('base_audit_actions.payload')
        ->and($sql->ipAddressTextExpression('base_audit_actions.ip_address'))->toBe('cast(base_audit_actions.ip_address as text)')
        ->and($sql->jsonIntegerExpression('base_audit_actions.payload', 'status'))->toBe("cast(json_extract(base_audit_actions.payload, '$.status') as integer)");
});

it('builds portable audit SQL expressions for mysql-compatible grammar', function (): void {
    config()->set('database.default', 'mysql');

    $sql = new AuditSearchSql;

    expect($sql->lowerTextExpression('base_audit_mutations.subject_id'))->toBe('lower(cast(base_audit_mutations.subject_id as char))')
        ->and($sql->jsonTextExpression('base_audit_actions.payload'))->toBe('cast(base_audit_actions.payload as char)')
        ->and($sql->ipAddressTextExpression('base_audit_actions.ip_address'))->toBe('cast(base_audit_actions.ip_address as char)')
        ->and($sql->jsonIntegerExpression('base_audit_actions.payload', 'status'))->toBe("cast(json_unquote(json_extract(base_audit_actions.payload, '$.status')) as signed)");
});

it('builds portable audit SQL expressions for postgres grammar', function (): void {
    config()->set('database.default', 'pgsql');

    $sql = new AuditSearchSql;

    expect($sql->jsonTextExpression('base_audit_actions.payload'))->toBe('base_audit_actions.payload::text')
        ->and($sql->ipAddressTextExpression('base_audit_actions.ip_address'))->toBe('cast(base_audit_actions.ip_address as text)')
        ->and($sql->jsonIntegerExpression('base_audit_actions.payload', 'status'))->toBe("nullif(base_audit_actions.payload->>'status', '')::int");
});
