<?php

use App\Base\Dashboard\DTO\WidgetDefinition;
use App\Base\Dashboard\Livewire\Index;
use App\Base\Dashboard\Services\DashboardLayout;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

const DASHBOARD_LEAVE_WIDGET = 'people.leave.pending-approvals';
const DASHBOARD_PERF_WIDGET = 'perf.request-health';
const DASHBOARD_AI_WIDGET = 'ai.operations-status';
const DASHBOARD_TEST_LEAVE_CONFIG = 'app/Modules/People/Leave/Config/dashboard.php';

beforeEach(function (): void {
    // The fixture must live at the real discovery path — but on machines
    // where blb-people is checked out, that file exists and belongs to the
    // module. Back it up and restore it, or this suite silently deletes a
    // real repo file on every run (which it did until 2026-07-13).
    $real = base_path(DASHBOARD_TEST_LEAVE_CONFIG);
    $this->dashboardLeaveConfigBackup = File::exists($real)
        ? File::get($real)
        : null;

    installDashboardLeaveFixture();
});

afterEach(function (): void {
    if ($this->dashboardLeaveConfigBackup !== null) {
        File::put(base_path(DASHBOARD_TEST_LEAVE_CONFIG), $this->dashboardLeaveConfigBackup);

        return;
    }

    File::delete(base_path(DASHBOARD_TEST_LEAVE_CONFIG));
    @rmdir(base_path('app/Modules/People/Leave/Config'));
    @rmdir(base_path('app/Modules/People/Leave'));
    @rmdir(base_path('app/Modules/People'));
});

function installDashboardLeaveFixture(): void
{
    $path = base_path(DASHBOARD_TEST_LEAVE_CONFIG);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
<?php

return [
    'widgets' => [
        [
            'id' => 'people.leave.pending-approvals',
            'label' => 'Leave Approvals',
            'description' => 'Pending leave approvals.',
            'icon' => 'heroicon-o-calendar-days',
            'permission' => 'admin.ai.agent.view',
            'component' => 'ai.widgets.operations-status',
            'size' => 1,
        ],
    ],
];
PHP);
}

function dashboardWidgetIds(array $widgets): array
{
    return array_map(fn (WidgetDefinition $widget): string => $widget->id, $widgets);
}

function createDashboardUser(): User
{
    setupAuthzRoles();

    $company = Company::factory()->create();

    return User::factory()->create([
        'company_id' => $company->id,
        'email_verified_at' => now(),
    ]);
}

function dashboardSavedLayout(User $user): ?array
{
    $settings = app(SettingsService::class);
    $scope = Scope::user((int) $user->getKey(), $user->getCompanyId());

    return $settings->has(DashboardLayout::SETTING_KEY, $scope)
        ? $settings->get(DashboardLayout::SETTING_KEY, $scope)
        : null;
}

function storeDashboardLayout(User $user, array $ids): void
{
    app(SettingsService::class)->set(
        DashboardLayout::SETTING_KEY,
        $ids,
        Scope::user((int) $user->getKey(), $user->getCompanyId()),
    );
}

it('shows no widgets to a user without any granting role', function (): void {
    $user = createDashboardUser();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertOk()
        ->assertViewHas('widgets', fn (array $widgets): bool => $widgets === []);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Leave Approvals');
});

it('shows capability-gated widgets to an admin in registry order', function (): void {
    Livewire::actingAs(createAdminUser())
        ->test(Index::class)
        ->assertViewHas(
            'widgets',
            function (array $widgets): bool {
                $ids = dashboardWidgetIds($widgets);

                // Base providers lead. Optional module widgets may follow in
                // discovery order before this test's People fixture.
                return array_slice($ids, 0, 2) === [DASHBOARD_PERF_WIDGET, DASHBOARD_AI_WIDGET]
                    && in_array(DASHBOARD_LEAVE_WIDGET, $ids, true);
            },
        );
});

it('uses one column by default and keeps narrow widgets in the trailing wide-screen column', function (): void {
    Livewire::actingAs(createAdminUser())
        ->test(Index::class)
        ->assertSeeHtml('class="grid gap-6 lg:grid-cols-2 xl:grid-flow-row-dense xl:grid-cols-3"')
        ->assertSeeHtml('lg:col-span-1 xl:col-start-3')
        ->assertSeeHtml('lg:col-span-2 xl:col-span-2');
});

it('persists remove, reorder, and add as a whole prefs layout', function (): void {
    $admin = createAdminUser();
    $initialIds = dashboardWidgetIds(app(DashboardLayout::class)->layoutFor($admin));
    $withoutAi = array_values(array_diff($initialIds, [DASHBOARD_AI_WIDGET]));
    $withAiAppended = [...$withoutAi, DASHBOARD_AI_WIDGET];
    $withAiMovedEarlier = $withAiAppended;
    $visible = app(DashboardLayout::class)->visibleFor($admin);
    $aiIndex = array_search(DASHBOARD_AI_WIDGET, $withAiMovedEarlier, true);
    $aiSize = $visible[DASHBOARD_AI_WIDGET]->size;
    $targetIndex = false;

    if ($aiIndex !== false) {
        for ($candidate = $aiIndex - 1; $candidate >= 0; $candidate--) {
            if ($visible[$withAiMovedEarlier[$candidate]]->size === $aiSize) {
                $targetIndex = $candidate;

                break;
            }
        }
    }

    if ($aiIndex !== false && $targetIndex !== false) {
        [$withAiMovedEarlier[$targetIndex], $withAiMovedEarlier[$aiIndex]] = [
            $withAiMovedEarlier[$aiIndex],
            $withAiMovedEarlier[$targetIndex],
        ];
    }

    $component = Livewire::actingAs($admin)->test(Index::class);

    $component->call('remove', DASHBOARD_AI_WIDGET);
    expect(dashboardSavedLayout($admin))->toBe($withoutAi);

    $component->call('add', DASHBOARD_AI_WIDGET);
    expect(dashboardSavedLayout($admin))->toBe($withAiAppended);

    $component->call('moveUp', DASHBOARD_AI_WIDGET);
    expect(dashboardSavedLayout($admin))->toBe($withAiMovedEarlier);

    $component->call('resetLayout');
    expect(dashboardSavedLayout($admin))->toBeNull();
});

it('persists a drag-drop reorder as a whole prefs layout', function (): void {
    $admin = createAdminUser();
    $initialIds = dashboardWidgetIds(app(DashboardLayout::class)->layoutFor($admin));

    expect(count($initialIds))->toBeGreaterThan(1);

    $movedId = $initialIds[0];
    $destination = count($initialIds) - 1;

    $component = Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('reorder', $movedId, $destination);

    expect(dashboardSavedLayout($admin))
        ->toBe([...array_slice($initialIds, 1), $movedId]);

    $component->call('reorder', $movedId, 0);

    expect(dashboardSavedLayout($admin))->toBe($initialIds);
});

it('ignores invalid drag-drop reorders without creating a custom layout', function (string $id, int $position): void {
    $admin = createAdminUser();
    $initialIds = dashboardWidgetIds(app(DashboardLayout::class)->layoutFor($admin));

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('reorder', $id === 'visible-widget' ? $initialIds[0] : $id, $position);

    expect(dashboardSavedLayout($admin))->toBeNull();
})->with([
    'unknown widget' => ['unknown-widget', 0],
    'negative position' => ['visible-widget', -1],
    'position past the end' => ['visible-widget', PHP_INT_MAX],
]);

it('skips stale or invisible widget ids in a saved layout silently', function (): void {
    $admin = createAdminUser();
    storeDashboardLayout($admin, ['bogus.widget', DASHBOARD_LEAVE_WIDGET]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertOk()
        ->assertViewHas(
            'widgets',
            fn (array $widgets): bool => dashboardWidgetIds($widgets) === [DASHBOARD_LEAVE_WIDGET],
        );
});

it('respects an explicitly emptied layout instead of restoring the default', function (): void {
    $admin = createAdminUser();
    storeDashboardLayout($admin, []);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertViewHas('widgets', fn (array $widgets): bool => $widgets === []);
});

it('never persists a widget id the user cannot see', function (): void {
    $user = createDashboardUser();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add', DASHBOARD_LEAVE_WIDGET);

    expect(dashboardSavedLayout($user))->toBeNull();
});
