<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Livewire\DataShare\Index as DataShareIndex;
use App\Base\Database\Livewire\DataShare\Settings as DataShareSettings;
use App\Base\Database\Models\DataShareEvent;
use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\DataShareTransferOffer;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Support\SettingsFieldValue;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\View\ViewException;
use Livewire\Livewire;

beforeEach(function (): void {
    setupAuthzRoles();
    app(SettingsService::class)->set('data_share.instance.id', 'identity-source-dev');
    app(SettingsService::class)->set('data_share.instance.name', 'Identity Source');
    app(SettingsService::class)->set('data_share.instance.role', 'development');
    app(SettingsService::class)->set('data_share.disk', 'local');
    app(SettingsService::class)->set('data_share.outgoing_path_prefix', 'data-share/outgoing');
    app(SettingsService::class)->set('data_share.incoming_path_prefix', 'data-share/incoming');
    app(SettingsService::class)->set('data_share.receiving_path_prefix', 'data-share/receiving');
    app(SettingsService::class)->set('data_share.offers.base_urls', "https://source.lan\nhttps://share.example.test");
    app(SettingsService::class)->set('data_share.offers.expiry_minutes', 60);
});

function dataShareIdentityAdmin(): User
{
    $company = provisionPlatformOperatorCompany();
    $user = User::factory()->create(['company_id' => $company->id]);
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);
    app(TenantContext::class)->set((int) $company->tenant_id);

    return $user;
}

function insertOutstandingOffer(array $overrides = []): void
{
    DataShareTransferOffer::query()->create(array_replace([
        'offer_id' => '01IDENTITYOFFER00000000001',
        'secret_hash' => hash('sha256', 'secret'),
        'secret' => 'secret',
        'published_by_actor_id' => 1,
        'package_id' => 'pkg-identity-1',
        'package_sha256' => str_repeat('a', 64),
        'package_path' => 'data-share/outgoing/pkg-identity-1.ndjson',
        'source_instance_id' => 'identity-source-dev',
        'source_name' => 'Identity Source',
        'source_role' => 'development',
        'scope_name' => 'tests/identity',
        'bytes' => 10,
        'metadata' => [],
        'status' => 'published',
        'expires_at' => now()->addHour(),
        'max_downloads' => 1,
        'download_count' => 0,
    ], $overrides));
}

function insertUnappliedReceipt(array $overrides = []): void
{
    DataShareReceipt::query()->create(array_replace([
        'package_id' => 'pkg-identity-receipt-1',
        'package_sha256' => str_repeat('b', 64),
        'package_path' => 'data-share/incoming/pkg-identity-receipt-1.ndjson',
        'source_instance_id' => 'other-source',
        'source_role' => 'development',
        'target_instance_id' => 'identity-source-dev',
        'scope_name' => 'tests/identity',
        'offer_id' => '01IDENTITYRECEIPT000000001',
        'status' => 'received',
        'received_at' => now(),
        'expires_at' => now()->addDay(),
        'metadata' => [],
    ], $overrides));
}

it('refuses an instance id change while an available offer exists', function (): void {
    $admin = dataShareIdentityAdmin();
    insertOutstandingOffer();

    Livewire::actingAs($admin)
        ->test(DataShareSettings::class)
        ->set('values.'.SettingsFieldValue::formKey('data_share.instance.id'), 'renamed-instance')
        ->call('save')
        ->assertHasErrors(['confirmIdentityChange'])
        ->assertSee('1 offer');

    expect(app(SettingsService::class)->get('data_share.instance.id'))->toBe('identity-source-dev')
        ->and(DataShareEvent::query()->where('action', 'identity_changed')->exists())->toBeFalse();
});

it('refuses a role change while an unapplied receipt exists', function (): void {
    $admin = dataShareIdentityAdmin();
    insertUnappliedReceipt();

    Livewire::actingAs($admin)
        ->test(DataShareSettings::class)
        ->set('values.'.SettingsFieldValue::formKey('data_share.instance.role'), 'staging')
        ->call('save')
        ->assertHasErrors(['confirmIdentityChange'])
        ->assertSee('1 unapplied');

    expect(app(SettingsService::class)->get('data_share.instance.role'))->toBe('development');
});

it('ignores revoked, expired and exhausted offers and applied receipts when counting', function (): void {
    $admin = dataShareIdentityAdmin();
    insertOutstandingOffer([
        'offer_id' => '01IDENTITYREVOKED000000001',
        'package_id' => 'pkg-revoked',
        'status' => 'revoked',
        'revoked_at' => now(),
    ]);
    insertOutstandingOffer([
        'offer_id' => '01IDENTITYEXPIRED000000001',
        'package_id' => 'pkg-expired',
        'status' => 'published',
        'expires_at' => now()->subMinute(),
    ]);
    insertOutstandingOffer([
        'offer_id' => '01IDENTITYEXHAUST000000001',
        'package_id' => 'pkg-exhausted',
        'status' => 'exhausted',
    ]);
    insertUnappliedReceipt([
        'package_id' => 'pkg-applied',
        'status' => 'applied',
    ]);

    Livewire::actingAs($admin)
        ->test(DataShareSettings::class)
        ->set('values.'.SettingsFieldValue::formKey('data_share.instance.id'), 'clean-rename')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDontSee(__('I understand — change identity anyway'));

    expect(app(SettingsService::class)->get('data_share.instance.id'))->toBe('clean-rename');
});

it('saves an identity change with no outstanding work without asking for confirmation', function (): void {
    $admin = dataShareIdentityAdmin();

    Livewire::actingAs($admin)
        ->test(DataShareSettings::class)
        ->set('values.'.SettingsFieldValue::formKey('data_share.instance.id'), 'free-rename')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('data_share.instance.id'))->toBe('free-rename')
        ->and(DataShareEvent::query()->where('action', 'identity_changed')->count())->toBe(1);
});

it('saves a confirmed identity change and records identity_changed in the ledger', function (): void {
    $admin = dataShareIdentityAdmin();
    insertOutstandingOffer();
    insertUnappliedReceipt();

    Livewire::actingAs($admin)
        ->test(DataShareSettings::class)
        ->set('values.'.SettingsFieldValue::formKey('data_share.instance.id'), 'confirmed-rename')
        ->set('confirmIdentityChange', true)
        ->call('save')
        ->assertHasNoErrors();

    $row = DataShareEvent::query()->where('action', 'identity_changed')->sole();
    expect(app(SettingsService::class)->get('data_share.instance.id'))->toBe('confirmed-rename')
        ->and($row->metadata['from']['id'])->toBe('identity-source-dev')
        ->and($row->metadata['to']['id'])->toBe('confirmed-rename')
        ->and($row->metadata['offers'])->toBe(1)
        ->and($row->metadata['unapplied'])->toBe(1)
        ->and(json_encode($row->metadata))->not->toContain('secret');
});

it('does not let a wire-set confirmIdentityChange bypass the capability check', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Identity Cap Deny']);
    app(TenantContext::class)->set((int) $tenant->id);
    $stranger = User::factory()->create(['company_id' => $company->id]);
    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $stranger->id,
        'capability_key' => 'admin.user.view',
        'is_allowed' => true,
    ]);
    insertOutstandingOffer();

    // Guard: SettingsForm::mount authorizeManage and Settings::save requireCapability.
    // Wire-setting confirmIdentityChange cannot open the page or reach save.
    $this->actingAs($stranger)
        ->get(route('admin.system.data-share.settings'))
        ->assertForbidden();

    expect(fn () => Livewire::actingAs($stranger)->test(DataShareSettings::class))
        ->toThrow(ViewException::class);

    expect(app(SettingsService::class)->get('data_share.instance.id'))->toBe('identity-source-dev')
        ->and(DataShareEvent::query()->where('action', 'identity_changed')->exists())->toBeFalse();
});

it('shows identity_changed rows on the History tab under the ambient tenant', function (): void {
    $admin = dataShareIdentityAdmin();
    DataShareEvent::query()->create([
        'action' => 'identity_changed',
        'actor_id' => $admin->id,
        'metadata' => [
            'from' => ['id' => 'old', 'role' => 'development'],
            'to' => ['id' => 'new', 'role' => 'staging'],
            'offers' => 0,
            'unapplied' => 0,
        ],
        'created_at' => now('UTC'),
    ]);

    Livewire::actingAs($admin)
        ->test(DataShareIndex::class)
        ->set('historyActionClass', 'identity')
        ->assertSee('identity_changed')
        ->assertSee('old')
        ->assertSee('new');
});
