<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Livewire\Companies\DepartmentTypes;
use App\Core\Company\Livewire\Companies\LegalEntityTypes;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\DepartmentType;
use App\Core\Company\Models\LegalEntityType;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

function companyTypeListOnlyUser(): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.company.list',
        'is_allowed' => true,
    ]);

    app(TenantContext::class)->set((int) $company->tenant_id);

    return $user;
}

dataset('global company type components', [
    'legal entity types' => [[
        'component' => LegalEntityTypes::class,
        'model' => LegalEntityType::class,
        'existing' => ['code' => 'llc', 'name' => 'Limited Liability Company', 'is_active' => true],
        'create' => ['createCode' => 'plc', 'createName' => 'Public Limited Company'],
        'renamed' => 'Renamed Legal Entity Type',
    ]],
    'department types' => [[
        'component' => DepartmentTypes::class,
        'model' => DepartmentType::class,
        'existing' => ['code' => 'ops', 'name' => 'Operations', 'category' => 'operational', 'is_active' => true],
        'create' => ['createCode' => 'revenue', 'createName' => 'Revenue', 'createCategory' => 'revenue'],
        'renamed' => 'Renamed Department Type',
    ]],
]);

test('list-only users cannot mutate global company type definitions', function (array $configuration): void {
    $this->actingAs(companyTypeListOnlyUser());

    /** @var class-string<Model> $model */
    $model = $configuration['model'];
    $type = $model::query()->create($configuration['existing']);
    $component = $configuration['component'];
    $denial = __('You do not have permission to perform this action.');

    $attempts = [
        fn () => Livewire::test($component)->set($configuration['create'])->call('createType'),
        fn () => Livewire::test($component)->call('saveField', $type->id, 'name', $configuration['renamed']),
        fn () => Livewire::test($component)->call('toggleActive', $type->id),
        fn () => Livewire::test($component)->call('deleteType', $type->id),
    ];

    foreach ($attempts as $attempt) {
        $attempt()->assertDispatched('notify', variant: 'error', message: $denial);
    }

    expect($model::query()->where('code', $configuration['create']['createCode'])->exists())->toBeFalse()
        ->and($type->refresh()->name)->toBe($configuration['existing']['name'])
        ->and($type->is_active)->toBeTrue()
        ->and($type->exists)->toBeTrue();
})->with('global company type components');

test('administrators retain global company type mutation access', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(LegalEntityTypes::class)
        ->set('createCode', 'plc')
        ->set('createName', 'Public Limited Company')
        ->call('createType');

    $departmentType = DepartmentType::query()->create([
        'code' => 'ops',
        'name' => 'Operations',
        'category' => 'operational',
        'is_active' => true,
    ]);

    Livewire::test(DepartmentTypes::class)->call('toggleActive', $departmentType->id);

    expect(LegalEntityType::query()->where('code', 'plc')->exists())->toBeTrue()
        ->and($departmentType->refresh()->is_active)->toBeFalse();
});
