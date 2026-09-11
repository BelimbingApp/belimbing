<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Providers\ProviderRegistry;
use App\Base\Foundation\Services\CompositionReport;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The composition endpoint (#623): operator-only. It reports what is mounted
 * and which commit each Domain checkout is at. It reads no CI configuration
 * and knows nothing about pins (#940).
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function compositionOperator(): User
{
    return createAdminUser();
}

/** A core_admin in a customer tenant: every capability, but not the operator. */
function compositionCustomerAdmin(): User
{
    setupAuthzRoles();
    [$tenant, $company] = createTenantWithCompany(['name' => 'Composition Customer Tenant']);
    $user = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail()->id,
    ]);
    app(TenantContext::class)->set((int) $tenant->id);

    return $user;
}

test('an operator reads every mounted module in resolved boot order', function (): void {
    $response = $this->actingAs(compositionOperator())
        ->getJson(route('admin.system.software.composition'))
        ->assertOk();

    $reader = new ModuleManifestReader([
        ApplicationTopology::baseRoot(), ApplicationTopology::coreRoot(), ApplicationTopology::domainsRoot(), ApplicationTopology::extensionsRoot(),
    ]);
    $expectedModules = array_keys($reader->moduleRoots());
    sort($expectedModules);
    $reported = array_column($response->json('modules'), 'module');
    sort($reported);

    // The resolved provider order is the boot order, expressed by module.
    $providers = ProviderRegistry::resolve();
    $bootOrder = $response->json('boot_order');
    $positions = array_column($response->json('modules'), 'boot_position', 'module');

    expect($reported)->toBe($expectedModules)
        ->and($bootOrder)->toHaveCount(count($providers))
        ->and($bootOrder)->toContain('base/foundation', 'core/company')
        ->and(array_search('base/foundation', $bootOrder, true))->toBeLessThan(array_search('core/company', $bootOrder, true))
        ->and($positions['core/company'])->toBe(array_search('App\\Core\\Company\\ServiceProvider', $providers, true))
        ->and(collect($response->json('modules'))->firstWhere('module', 'core/company'))
        ->toMatchArray(['layer' => 'core', 'path' => 'app/Core/Company', 'domain' => null, 'mounted_ref' => null]);
});

test('a mounted domain reports the commit its checkout is at, and its id comes from the mount directory', function (): void {
    $root = createFakeDomainCheckout('CompositionProbe', 'composition_probe_rows', 'composition.probe', ['withProvider' => true, 'withGit' => true]);

    try {
        Process::path($root)->run(['git', '-c', 'user.name=probe', '-c', 'user.email=probe@example.test', 'commit', '-q', '--allow-empty', '-m', 'probe']);
        $mountedRef = trim(Process::path($root)->run(['git', 'rev-parse', 'HEAD'])->output());

        $module = collect($this->actingAs(compositionOperator())
            ->getJson(route('admin.system.software.composition'))
            ->assertOk()
            ->json('modules'))->first(fn (array $module): bool => ($module['domain'] ?? null) === 'composition-probe');

        // No descriptor is consulted: CompositionProbe on disk is
        // composition-probe in the report, by the mount-directory convention.
        expect($module)->not->toBeNull()
            ->and($module['mounted_ref'])->toBe($mountedRef)
            ->and($module['layer'])->toBe('domain');
    } finally {
        File::deleteDirectory($root);
    }
});

test('a mount whose git answer is not a commit sha publishes no mounted ref', function (string $answer, int $exit): void {
    $root = createFakeDomainCheckout('CompositionOdd', 'composition_odd_rows', 'composition.odd', ['withProvider' => true, 'withGit' => true]);

    try {
        app()->instance(CompositionReport::class, new class($answer, $exit) extends CompositionReport
        {
            public function __construct(private string $answer, private int $exit) {}

            protected function gitHead(string $mount): array
            {
                return [$this->exit, $this->answer];
            }
        });

        $module = collect($this->actingAs(compositionOperator())
            ->getJson(route('admin.system.software.composition'))->assertOk()->json('modules'))
            ->first(fn (array $module): bool => str_contains($module['path'], 'CompositionOdd'));

        expect($module['domain'])->toBe('composition-odd')
            ->and($module['mounted_ref'])->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    'git exited non-zero with an error line' => ['fatal: not a git repository', 128],
    'git exited zero with a symbolic name' => ['HEAD', 0],
    'git exited zero with a short sha' => ['0dc53bba', 0],
]);

test('a customer-tenant admin is refused even with every capability', function (): void {
    $this->actingAs(compositionCustomerAdmin())
        ->getJson(route('admin.system.software.composition'))
        ->assertForbidden();
});

test('an operator-tenant user without the capability is refused', function (): void {
    $operator = compositionOperator();
    $user = User::factory()->create(['company_id' => $operator->company_id]);

    $this->actingAs($user)
        ->getJson(route('admin.system.software.composition'))
        ->assertForbidden();
});

test('a guest is sent to login', function (): void {
    $this->get(route('admin.system.software.composition'))->assertRedirect(route('login'));
});
