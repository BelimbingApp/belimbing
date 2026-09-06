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
 * The composition endpoint (#623): operator-only, and its facts are the same
 * ones the composed-application smoke test holds the pins to.
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
        ->toMatchArray(['layer' => 'core', 'path' => 'app/Core/Company', 'domain' => null, 'pinned_ref' => null, 'matches_pin' => null]);
});

test('a mounted domain reports the descriptor pin beside the ref it is checked out at', function (): void {
    $root = createFakeDomainCheckout('CompositionProbe', 'composition_probe_rows', 'composition.probe', ['withProvider' => true, 'withGit' => true]);
    $descriptor = storage_path('framework/testing/composition-descriptor-'.bin2hex(random_bytes(4)).'.json');
    File::ensureDirectoryExists(dirname($descriptor));

    try {
        Process::path($root)->run(['git', '-c', 'user.name=probe', '-c', 'user.email=probe@example.test', 'commit', '-q', '--allow-empty', '-m', 'probe']);
        $mountedRef = trim(Process::path($root)->run(['git', 'rev-parse', 'HEAD'])->output());
        $stalePin = str_repeat('0', 40);

        file_put_contents($descriptor, json_encode(['domains' => [
            'composition-probe' => ['repo' => 'BelimbingApp/blb-composition-probe', 'path' => 'app/Domains/CompositionProbe', 'ref' => $mountedRef],
        ]]));
        app()->instance(CompositionReport::class, new CompositionReport($descriptor));

        $module = collect($this->actingAs(compositionOperator())
            ->getJson(route('admin.system.software.composition'))
            ->assertOk()
            ->json('modules'))->first(fn (array $module): bool => ($module['domain'] ?? null) === 'composition-probe');

        expect($module)->not->toBeNull()
            ->and($module['pinned_ref'])->toBe($mountedRef)
            ->and($module['mounted_ref'])->toBe($mountedRef)
            ->and($module['matches_pin'])->toBeTrue()
            ->and($module['layer'])->toBe('domain');

        // Advance the descriptor past the mount: the report must say so, the
        // same disagreement scripts/ci/composed-smoke.php refuses.
        file_put_contents($descriptor, json_encode(['domains' => [
            'composition-probe' => ['repo' => 'BelimbingApp/blb-composition-probe', 'path' => 'app/Domains/CompositionProbe', 'ref' => $stalePin],
        ]]));
        app()->instance(CompositionReport::class, new CompositionReport($descriptor));

        $module = collect($this->actingAs(compositionOperator())
            ->getJson(route('admin.system.software.composition'))
            ->json('modules'))->first(fn (array $module): bool => ($module['domain'] ?? null) === 'composition-probe');

        expect($module['pinned_ref'])->toBe($stalePin)
            ->and($module['mounted_ref'])->toBe($mountedRef)
            ->and($module['matches_pin'])->toBeFalse();
    } finally {
        File::deleteDirectory($root);
        File::delete($descriptor);
    }
});

test('a descriptor whose entries are unusable is reported as broken, not as no descriptor at all', function (): void {
    $root = createFakeDomainCheckout('CompositionBroken', 'composition_broken_rows', 'composition.broken', ['withProvider' => true, 'withGit' => true]);
    $descriptor = storage_path('framework/testing/composition-broken-'.bin2hex(random_bytes(4)).'.json');
    File::ensureDirectoryExists(dirname($descriptor));

    try {
        Process::path($root)->run(['git', '-c', 'user.name=probe', '-c', 'user.email=probe@example.test', 'commit', '-q', '--allow-empty', '-m', 'probe']);
        $mountedRef = trim(Process::path($root)->run(['git', 'rev-parse', 'HEAD'])->output());

        // The descriptor exists and names this mount, but the entry has no
        // ref, and a second entry has no path: unreadable pins, not absent ones.
        file_put_contents($descriptor, json_encode(['domains' => [
            'composition-broken' => ['repo' => 'BelimbingApp/blb-composition-broken', 'path' => 'app/Domains/CompositionBroken', 'ref' => 'main'],
            'pathless' => ['repo' => 'BelimbingApp/blb-pathless', 'ref' => str_repeat('a', 40)],
        ]]));
        app()->instance(CompositionReport::class, new CompositionReport($descriptor));

        $report = $this->actingAs(compositionOperator())
            ->getJson(route('admin.system.software.composition'))->assertOk()->json();

        $module = collect($report['modules'])->first(fn (array $module): bool => str_contains($module['path'], 'CompositionBroken'));

        expect($report['descriptor'])->not->toBeNull()
            ->and($report['descriptor_issues'])->toBe([
                'domain [composition-broken] at app/Domains/CompositionBroken has no immutable 40-character ref',
                'domain [pathless] has no mount path',
            ])
            ->and($module['domain'])->toBe('composition-broken')
            ->and($module['pinned_ref'])->toBeNull()
            ->and($module['mounted_ref'])->toBe($mountedRef)
            ->and($module['matches_pin'])->toBeNull();

        // A descriptor that yields no usable pin at all is still a descriptor
        // that was read, and says why nothing could be pinned.
        foreach ([
            ['domains' => ['pathless' => ['repo' => 'BelimbingApp/blb-pathless', 'ref' => str_repeat('a', 40)]]],
            ['schema_version' => 1],
        ] as $unusable) {
            file_put_contents($descriptor, json_encode($unusable));
            app()->instance(CompositionReport::class, new CompositionReport($descriptor));
            $report = $this->actingAs(compositionOperator())
                ->getJson(route('admin.system.software.composition'))->assertOk()->json();

            expect($report['descriptor'])->not->toBeNull()
                ->and($report['descriptor_issues'])->toHaveCount(1)
                ->and(collect($report['modules'])->pluck('domain')->filter()->all())->toBe([]);
        }
    } finally {
        File::deleteDirectory($root);
        File::delete($descriptor);
    }
});

test('a mount whose git answer is not a commit sha publishes no mounted ref', function (string $answer, int $exit): void {
    $root = createFakeDomainCheckout('CompositionOdd', 'composition_odd_rows', 'composition.odd', ['withProvider' => true, 'withGit' => true]);
    $descriptor = storage_path('framework/testing/composition-odd-'.bin2hex(random_bytes(4)).'.json');
    File::ensureDirectoryExists(dirname($descriptor));

    try {
        file_put_contents($descriptor, json_encode(['domains' => [
            'composition-odd' => ['repo' => 'BelimbingApp/blb-composition-odd', 'path' => 'app/Domains/CompositionOdd', 'ref' => str_repeat('b', 40)],
        ]]));
        app()->instance(CompositionReport::class, new class($descriptor, $answer, $exit) extends CompositionReport
        {
            public function __construct(string $descriptor, private string $answer, private int $exit)
            {
                parent::__construct($descriptor);
            }

            protected function gitHead(string $mount): array
            {
                return [$this->exit, $this->answer];
            }
        });

        $module = collect($this->actingAs(compositionOperator())
            ->getJson(route('admin.system.software.composition'))->assertOk()->json('modules'))
            ->first(fn (array $module): bool => str_contains($module['path'], 'CompositionOdd'));

        expect($module['domain'])->toBe('composition-odd')
            ->and($module['mounted_ref'])->toBeNull()
            ->and($module['matches_pin'])->toBeNull();
    } finally {
        File::deleteDirectory($root);
        File::delete($descriptor);
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
