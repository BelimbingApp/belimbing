<?php

use App\Base\Foundation\Compatibility\LegacyApplicationClassMap;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Address\Exceptions\AddressTenantAssignmentException;
use App\Core\Address\Models\Address;
use App\Core\Address\Models\Addressable;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\Geonames\Models\Admin1;
use App\Core\Geonames\Models\Country;
use App\Core\Geonames\Models\Postcode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

const ADDRESS_UI_WAREHOUSE_LINE = '88 River Road';
const ADDRESS_UI_BOSTON_POSTCODE = '02110';
const ADDRESS_UI_KUALA_LUMPUR = 'Kuala Lumpur';
const ADDRESS_UI_KUALA_LUMPUR_POSTCODE = '50450';

/**
 * The address tenancy backfill migration, loaded as an executable instance so
 * tests can drive its up/down halves directly.
 */
function addressTenancyMigration(): object
{
    return requireMigrationOnce(app_path('Core/Address/Database/Migrations/0200_01_05_000002_add_tenant_to_addresses.php'));
}

/**
 * The pre-cutover morph name for a model class, as rows written before the
 * four-root topology normalization still record it.
 */
function legacyAddressableType(string $class): string
{
    $equivalents = LegacyApplicationClassMap::equivalents($class);

    expect($equivalents)->toHaveCount(2);

    return $equivalents[1];
}

test('guests are redirected to login from addresses pages', function (): void {
    $this->get(route('admin.addresses.index'))->assertRedirect(route('login'));
    $this->get(route('admin.addresses.create'))->assertRedirect(route('login'));
});

test('authenticated users can view address pages', function (): void {
    $user = createAdminUser();
    $address = Address::query()->create([
        'label' => 'HQ',
        'line1' => '123 Main Street',
        'locality' => 'Springfield',
        'verificationStatus' => 'unverified',
    ]);

    $this->actingAs($user);

    $this->get(route('admin.addresses.index'))->assertOk();
    $this->get(route('admin.addresses.create'))->assertOk();
    $this->get(route('admin.addresses.show', $address))->assertOk();
});

test('address pages and company attachment fail closed across tenants', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $ownAddress = Address::factory()->create([
        'tenant_id' => $company->tenant_id,
        'label' => 'Warehouse Alpha Visible',
        'country_iso' => null,
    ]);
    [$otherTenant] = createTenantWithCompany(['name' => 'Other Address Tenant']);
    $foreignAddress = Address::factory()->create([
        'tenant_id' => $otherTenant->id,
        'label' => 'Warehouse Beta Hidden',
        'country_iso' => null,
    ]);

    $this->actingAs($user)
        ->get(route('admin.addresses.index'))
        ->assertOk()
        ->assertSee($ownAddress->label)
        ->assertDontSee($foreignAddress->label);

    Livewire::test('admin.addresses.index')
        ->set('search', 'Warehouse')
        ->assertOk()
        ->assertSee($ownAddress->label)
        ->assertDontSee($foreignAddress->label);

    $this->get(route('admin.addresses.show', $foreignAddress))->assertNotFound();

    Livewire::test('admin.companies.show', ['company' => $company])
        ->set('attachAddressId', $foreignAddress->id)
        ->call('attachAddress');

    expect($company->addresses()->whereKey($foreignAddress->id)->exists())->toBeFalse();
});

test('address pivot writes reject cross-tenant ownership through every public path', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    [$otherTenant] = createTenantWithCompany(['name' => 'Foreign Address Owner Tenant']);
    $foreignAddress = Address::factory()->create([
        'tenant_id' => $otherTenant->id,
        'country_iso' => null,
    ]);

    expect(fn () => $company->addresses()->attach($foreignAddress->id))
        ->toThrow(AddressTenantAssignmentException::class)
        ->and(DB::table('addressables')->where('address_id', $foreignAddress->id)->exists())
        ->toBeFalse();

    expect(fn () => $company->addresses()->sync([$foreignAddress->id]))
        ->toThrow(AddressTenantAssignmentException::class)
        ->and(DB::table('addressables')->where('address_id', $foreignAddress->id)->exists())
        ->toBeFalse();

    expect(fn () => Addressable::query()->create([
        'address_id' => $foreignAddress->id,
        'addressable_type' => $company->getMorphClass(),
        'addressable_id' => $company->id,
    ]))->toThrow(AddressTenantAssignmentException::class)
        ->and(DB::table('addressables')->where('address_id', $foreignAddress->id)->exists())
        ->toBeFalse();
});

test('address can be created from create page component', function (): void {
    Country::query()->updateOrCreate(
        ['iso' => 'US'],
        [
            'iso3' => 'USA',
            'iso_numeric' => '840',
            'country' => 'United States',
            'continent' => 'NA',
        ]
    );

    $user = createAdminUser();
    $this->actingAs($user);
    app(TenantContext::class)->set((int) $user->tenant_id);

    Livewire::test('admin.addresses.create')
        ->set('label', 'Warehouse')
        ->set('line1', ADDRESS_UI_WAREHOUSE_LINE)
        ->set('countryIso', 'us')
        ->set('locality', 'Boston')
        ->set('postcode', ADDRESS_UI_BOSTON_POSTCODE)
        ->set('verificationStatus', 'verified')
        ->call('store')
        ->assertRedirect(route('admin.addresses.index'));

    $address = Address::query()
        ->where('label', 'Warehouse')
        ->where('line1', ADDRESS_UI_WAREHOUSE_LINE)
        ->latest('id')
        ->first();

    expect($address)
        ->not()->toBeNull()
        ->and($address->label)
        ->toBe('Warehouse')
        ->and($address->line1)
        ->toBe(ADDRESS_UI_WAREHOUSE_LINE)
        ->and($address->locality)
        ->toBe('Boston')
        ->and($address->postcode)
        ->toBe(ADDRESS_UI_BOSTON_POSTCODE)
        ->and($address->country_iso)
        ->toBe('US')
        ->and($address->verificationStatus)
        ->toBe('verified');
});

test('address detail saves location as a grouped edit', function (): void {
    Country::query()->create([
        'iso' => 'US',
        'iso3' => 'USA',
        'iso_numeric' => '840',
        'country' => 'United States',
        'continent' => 'NA',
    ]);
    Country::query()->create([
        'iso' => 'MY',
        'iso3' => 'MYS',
        'iso_numeric' => '458',
        'country' => 'Malaysia',
        'continent' => 'AS',
    ]);
    Admin1::query()->create(['code' => 'MY.14', 'name' => ADDRESS_UI_KUALA_LUMPUR]);
    Postcode::query()->create([
        'country_iso' => 'MY',
        'postcode' => ADDRESS_UI_KUALA_LUMPUR_POSTCODE,
        'place_name' => ADDRESS_UI_KUALA_LUMPUR,
        'admin1Code' => '14',
        'admin_name1' => ADDRESS_UI_KUALA_LUMPUR,
    ]);

    $user = createAdminUser();
    $address = Address::query()->create([
        'label' => 'HQ',
        'line1' => '1 Old Road',
        'country_iso' => 'US',
        'postcode' => ADDRESS_UI_BOSTON_POSTCODE,
        'locality' => 'Boston',
        'verificationStatus' => 'unverified',
    ]);

    $this->actingAs($user);

    Livewire::test('admin.addresses.show', ['address' => $address])
        ->call('openLocationEditor')
        ->set('countryIso', 'MY')
        ->set('postcode', ADDRESS_UI_KUALA_LUMPUR_POSTCODE)
        ->assertSet('admin1Code', 'MY.14')
        ->assertSet('locality', ADDRESS_UI_KUALA_LUMPUR);

    expect($address->fresh()->country_iso)->toBe('US');

    Livewire::test('admin.addresses.show', ['address' => $address])
        ->call('openLocationEditor')
        ->set('countryIso', 'MY')
        ->set('postcode', ADDRESS_UI_KUALA_LUMPUR_POSTCODE)
        ->call('saveLocation')
        ->assertSet('editingLocation', false)
        ->assertSee('Malaysia');

    $address->refresh();

    expect($address->country_iso)
        ->toBe('MY')
        ->and($address->admin1Code)
        ->toBe('MY.14')
        ->and($address->postcode)
        ->toBe(ADDRESS_UI_KUALA_LUMPUR_POSTCODE)
        ->and($address->locality)
        ->toBe(ADDRESS_UI_KUALA_LUMPUR);
});

test('address tenancy migration preflight rejects unowned addresses when multiple tenants exist', function (): void {
    createTenant(['name' => 'Second Tenant']);
    $migration = addressTenancyMigration();
    $migration->down();

    $addressId = DB::table('addresses')->insertGetId([
        'label' => 'Ambiguous Unowned Address',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        expect(fn () => $migration->up())->toThrow(
            RuntimeException::class,
            "address {$addressId} has no tenant-owned link",
        );
        expect(Schema::hasColumn('addresses', 'tenant_id'))->toBeFalse();
    } finally {
        DB::table('addresses')->where('id', $addressId)->delete();
        $migration->up();
    }
});

test('address tenancy migration backfills links recorded under pre-topology morph names', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $migration = addressTenancyMigration();
    $migration->down();

    $companyAddressId = DB::table('addresses')->insertGetId([
        'label' => 'Legacy Company Morph Address',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $employeeAddressId = DB::table('addresses')->insertGetId([
        'label' => 'Legacy Employee Morph Address',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // Morph names as written before the four-root topology normalization,
    // which sorts after this migration and so has not rewritten them yet
    // when a catch-up migrate run reaches the tenancy backfill.
    DB::table('addressables')->insert([
        [
            'address_id' => $companyAddressId,
            'addressable_type' => legacyAddressableType(Company::class),
            'addressable_id' => $company->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'address_id' => $employeeAddressId,
            'addressable_type' => legacyAddressableType(Employee::class),
            'addressable_id' => $employee->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    try {
        $migration->up();

        expect((int) DB::table('addresses')->where('id', $companyAddressId)->value('tenant_id'))
            ->toBe((int) $company->tenant_id)
            ->and((int) DB::table('addresses')->where('id', $employeeAddressId)->value('tenant_id'))
            ->toBe((int) $company->tenant_id);
    } finally {
        DB::table('addressables')->whereIn('address_id', [$companyAddressId, $employeeAddressId])->delete();
        DB::table('addresses')->whereIn('id', [$companyAddressId, $employeeAddressId])->delete();

        if (! Schema::hasColumn('addresses', 'tenant_id')) {
            $migration->up();
        }
    }
});

test('address tenancy migration resolves companies on a pre-tenancy schema', function (): void {
    // 0200_01_07_001003 adds companies.tenant_id and sorts after this
    // migration, so a database catching up across both releases in one migrate
    // run has no column to read. Reproducing that means a schema without the
    // column at all, which the suite's own database cannot offer: it is fully
    // migrated, and dropping the column there would mean tearing down the
    // foreign-key web around companies. A throwaway connection carrying only
    // the tables the backfill touches reproduces it exactly and cheaply.
    $originalConnection = DB::getDefaultConnection();
    config(['database.connections.address_pre_tenancy' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('address_pre_tenancy');

    try {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
        });
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->timestamps();
        });
        Schema::create('addressables', function (Blueprint $table): void {
            $table->unsignedBigInteger('address_id');
            $table->string('addressable_type');
            $table->unsignedBigInteger('addressable_id');
            $table->timestamps();
        });

        // A second tenant rules out the migration's single-tenant fallback, so
        // the assignments below can only come from resolving the company.
        DB::table('tenants')->insert([
            ['id' => Tenant::LICENSEE_TENANT_ID, 'name' => 'Legacy Licensee'],
            ['id' => Tenant::LICENSEE_TENANT_ID + 6, 'name' => 'Later Tenant'],
        ]);
        $companyId = DB::table('companies')->insertGetId(['name' => 'Pre-Tenancy Company']);
        $employeeId = DB::table('employees')->insertGetId(['company_id' => $companyId]);
        $companyAddressId = DB::table('addresses')->insertGetId([
            'label' => 'Pre-Tenancy Company Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employeeAddressId = DB::table('addresses')->insertGetId([
            'label' => 'Pre-Tenancy Employee Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('addressables')->insert([
            [
                'address_id' => $companyAddressId,
                'addressable_type' => Company::class,
                'addressable_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'address_id' => $employeeAddressId,
                'addressable_type' => Employee::class,
                'addressable_id' => $employeeId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        expect(Schema::hasColumn('companies', 'tenant_id'))->toBeFalse();

        addressTenancyMigration()->up();

        expect((int) DB::table('addresses')->where('id', $companyAddressId)->value('tenant_id'))
            ->toBe(Tenant::LICENSEE_TENANT_ID)
            ->and((int) DB::table('addresses')->where('id', $employeeAddressId)->value('tenant_id'))
            ->toBe(Tenant::LICENSEE_TENANT_ID);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('address_pre_tenancy');
    }
});
