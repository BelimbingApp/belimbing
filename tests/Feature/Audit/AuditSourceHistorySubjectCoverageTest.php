<?php

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Listeners\MutationListener;
use App\Base\Audit\Models\AuditMutation;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemFitment;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Address\Models\Addressable;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\CompanyRelationship;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Company\Models\DepartmentType;
use App\Modules\Core\Company\Models\ExternalAccess;
use App\Modules\Core\Company\Models\RelationshipType;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

const AUDIT_SUBJECT_COVERAGE_TRACE = 'SUBJCVRG0001';

beforeEach(function (): void {
    app()->forgetInstance(RequestContext::class);
    app()->singleton(RequestContext::class, fn () => new RequestContext(
        traceId: AUDIT_SUBJECT_COVERAGE_TRACE,
        ipAddress: '127.0.0.1',
        url: 'https://example.test/audit-subject-coverage',
        actorType: PrincipalType::USER->value,
        actorId: 1,
        companyId: 1,
    ));
});

function flushAuditSubjectCoverageBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $method = $reflection->getMethod('flush');
    $method->invoke($buffer);
}

function expectAuditSubjectRow(string $auditableType, string $subjectName, int|string $subjectId, string $source = 'listener'): void
{
    expect(
        AuditMutation::query()
            ->where('auditable_type', $auditableType)
            ->where('subject_name', $subjectName)
            ->where('subject_id', (string) $subjectId)
            ->where('source', $source)
            ->exists()
    )->toBeTrue("Expected {$auditableType} to write {$source} subject {$subjectName}#{$subjectId}");
}

it('writes direct source-history subjects for first-wave record models', function (): void {
    [$company, $employee, $address, $item] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->minimal()->create(['name' => 'Coverage Company']);
        $employee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Coverage Employee']);
        $address = Address::factory()->create(['country_iso' => null, 'line1' => '1 Old Road']);
        $item = Item::factory()->create(['company_id' => $company->id, 'title' => 'Coverage Item']);

        return [$company, $employee, $address, $item];
    });

    $company->update(['name' => 'Coverage Company Renamed']);
    $employee->update(['full_name' => 'Coverage Employee Renamed']);
    $address->update(['line1' => '2 New Road']);
    $item->update(['title' => 'Coverage Item Renamed']);

    flushAuditSubjectCoverageBuffer();

    expectAuditSubjectRow(Company::class, 'company', $company->id);
    expectAuditSubjectRow(Employee::class, 'employee', $employee->id);
    expectAuditSubjectRow(Address::class, 'address', $address->id);
    expectAuditSubjectRow(Item::class, 'item', $item->id);
});

it('expands address mutations into company and employee record histories', function (): void {
    [$company, $employee, $address] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->minimal()->create(['name' => 'Address Owner Company']);
        $employee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Address Owner Employee']);
        $address = Address::factory()->create(['country_iso' => null, 'line1' => '10 Linked Road']);

        Addressable::query()->create([
            'address_id' => $address->id,
            'addressable_type' => $company->getMorphClass(),
            'addressable_id' => $company->id,
            'kind' => ['billing'],
        ]);

        Addressable::query()->create([
            'address_id' => $address->id,
            'addressable_type' => $employee->getMorphClass(),
            'addressable_id' => $employee->id,
            'kind' => ['home'],
        ]);

        return [$company, $employee, $address];
    });

    $address->update(['line1' => '11 Linked Road']);

    flushAuditSubjectCoverageBuffer();

    expectAuditSubjectRow(Address::class, 'address', $address->id);
    expectAuditSubjectRow(Address::class, 'company', $company->id, 'expanded');
    expectAuditSubjectRow(Address::class, 'employee', $employee->id, 'expanded');
});

it('includes company relationships departments and external access in company history', function (): void {
    [$company, $relatedCompany, $relationshipType, $departmentType, $user] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->minimal()->create(['name' => 'Primary Company']);
        $relatedCompany = Company::factory()->minimal()->create(['name' => 'Related Company']);
        $relationshipType = RelationshipType::query()->firstOrCreate(
            ['code' => 'customer'],
            [
                'name' => 'Customer',
                'description' => 'Customer relationship - company purchases from us',
                'is_external' => true,
                'is_active' => true,
                'metadata' => ['default_permissions' => ['view_orders', 'view_invoices', 'view_statements']],
            ],
        );
        $departmentType = DepartmentType::query()->create([
            'code' => 'coverage_department',
            'name' => 'Coverage Department',
            'category' => 'operations',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);

        return [$company, $relatedCompany, $relationshipType, $departmentType, $user];
    });

    $department = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $departmentType->id,
        'status' => 'active',
    ]);

    $relationship = CompanyRelationship::query()->create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $relationshipType->id,
        'effective_from' => now()->toDateString(),
    ]);

    $externalAccess = ExternalAccess::query()->create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'permissions' => ['view_orders'],
        'is_active' => true,
        'access_granted_at' => now(),
    ]);

    flushAuditSubjectCoverageBuffer();

    expectAuditSubjectRow(Department::class, 'company', $company->id);
    expectAuditSubjectRow(CompanyRelationship::class, 'company', $company->id);
    expectAuditSubjectRow(CompanyRelationship::class, 'company', $relatedCompany->id, 'expanded');
    expectAuditSubjectRow(ExternalAccess::class, 'user', $user->id);
    expectAuditSubjectRow(ExternalAccess::class, 'company', $company->id, 'expanded');
    expectAuditSubjectRow(ExternalAccess::class, 'company', $relatedCompany->id, 'expanded');

    expect($externalAccess->relationship_id)->toBe($relationship->id);
});

it('includes linked users in employee history', function (): void {
    [$employee, $user] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->minimal()->create(['name' => 'Employee User Company']);
        $employee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Employee With User']);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'name' => 'Employee User',
        ]);

        return [$employee, $user];
    });

    $user->update(['name' => 'Employee User Renamed']);

    flushAuditSubjectCoverageBuffer();

    expectAuditSubjectRow(User::class, 'user', $user->id);
    expectAuditSubjectRow(User::class, 'employee', $employee->id, 'expanded');
});

it('includes item-related records in item history and excludes noisy marketplace fields', function (): void {
    [$company, $item, $attribute] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->minimal()->create(['name' => 'Item Company']);
        $item = Item::factory()->create(['company_id' => $company->id, 'title' => 'History Item']);
        $attribute = Attribute::query()->create([
            'company_id' => $company->id,
            'code' => 'colour',
            'name' => 'Colour',
            'type' => Attribute::TYPE_TEXT,
            'sort_order' => 1,
        ]);

        return [$company, $item, $attribute];
    });

    ItemFitment::query()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'display_make' => 'Toyota',
        'display_model' => 'Hilux',
        'source' => ItemFitment::SOURCE_OPERATOR,
        'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
    ]);

    AttributeValue::query()->create([
        'item_id' => $item->id,
        'attribute_id' => $attribute->id,
        'value' => ['blue'],
        'display_value' => 'Blue',
    ]);

    Listing::query()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'channel' => 'ebay',
        'external_listing_id' => 'LISTING-SUBJECT-COVERAGE',
        'marketplace_id' => 'EBAY_US',
        'title' => 'History Listing',
        'status' => 'ACTIVE',
        'management_state' => Listing::MANAGEMENT_IMPORTED,
        'drift_status' => Listing::DRIFT_UNKNOWN,
        'last_synced_at' => now(),
        'raw_payload' => ['inventory_item' => ['sku' => $item->sku]],
    ]);

    ListingDraft::query()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'channel' => 'ebay',
        'marketplace_id' => 'EBAY_US',
        'title' => 'History Draft',
        'status' => ListingDraft::STATUS_DRAFT,
        'management_state' => ListingDraft::MANAGEMENT_LOCAL,
        'readiness_status' => 'blocked',
        'readiness_snapshot' => ['blockers' => [['key' => 'missing_photo']]],
        'metadata_checked_at' => now(),
    ]);

    flushAuditSubjectCoverageBuffer();

    expectAuditSubjectRow(ItemFitment::class, 'item', $item->id);
    expectAuditSubjectRow(AttributeValue::class, 'item', $item->id);
    expectAuditSubjectRow(Listing::class, 'item', $item->id);
    expectAuditSubjectRow(ListingDraft::class, 'item', $item->id);

    $listingMutation = AuditMutation::query()
        ->where('auditable_type', Listing::class)
        ->where('subject_name', 'item')
        ->firstOrFail();

    $draftMutation = AuditMutation::query()
        ->where('auditable_type', ListingDraft::class)
        ->where('subject_name', 'item')
        ->firstOrFail();

    expect($listingMutation->new_values)
        ->not->toHaveKey('last_synced_at')
        ->not->toHaveKey('raw_payload')
        ->and($draftMutation->new_values)
        ->not->toHaveKey('metadata_checked_at')
        ->not->toHaveKey('readiness_snapshot');
});

it('includes workflow configuration rows in workflow history', function (): void {
    $workflow = MutationListener::withoutAuditing(
        fn (): Workflow => Workflow::query()->create([
            'code' => 'audit_workflow_subject_flow',
            'label' => 'Audit Workflow Subject Flow',
            'is_active' => true,
        ])
    );

    $workflow->update(['label' => 'Audit Workflow Subject Flow Renamed']);

    StatusConfig::query()->create([
        'flow' => $workflow->code,
        'code' => 'open',
        'label' => 'Open',
        'position' => 1,
        'is_active' => true,
    ]);

    StatusTransition::query()->create([
        'flow' => $workflow->code,
        'from_code' => 'open',
        'to_code' => 'closed',
        'label' => 'Close',
        'position' => 1,
        'is_active' => true,
    ]);

    KanbanColumn::query()->create([
        'flow' => $workflow->code,
        'code' => 'todo',
        'label' => 'To Do',
        'position' => 1,
        'is_active' => true,
    ]);

    flushAuditSubjectCoverageBuffer();

    expectAuditSubjectRow(Workflow::class, 'workflow', $workflow->id);
    expectAuditSubjectRow(StatusConfig::class, 'workflow', $workflow->id);
    expectAuditSubjectRow(StatusTransition::class, 'workflow', $workflow->id);
    expectAuditSubjectRow(KanbanColumn::class, 'workflow', $workflow->id);
});
