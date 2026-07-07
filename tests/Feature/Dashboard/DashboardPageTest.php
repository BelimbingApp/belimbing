<?php

use App\Base\Dashboard\DTO\WidgetDefinition;
use App\Base\Dashboard\Livewire\Index;
use App\Base\Dashboard\Services\DashboardLayout;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

const DASHBOARD_LEAVE_WIDGET = 'people.leave.pending-approvals';
const DASHBOARD_AI_WIDGET = 'ai.operations-status';
const DASHBOARD_TEST_LEAVE_CONFIG = 'app/Modules/People/Leave/Config/dashboard.php';

beforeEach(function (): void {
    installDashboardLeaveFixture();
});

afterEach(function (): void {
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
            fn (array $widgets): bool => array_slice(dashboardWidgetIds($widgets), 0, 2) === [DASHBOARD_AI_WIDGET, DASHBOARD_LEAVE_WIDGET],
        );
});

it('persists remove, reorder, and add as a whole prefs layout', function (): void {
    $admin = createAdminUser();
    $initialIds = dashboardWidgetIds(app(DashboardLayout::class)->layoutFor($admin));
    $withoutAi = array_values(array_diff($initialIds, [DASHBOARD_AI_WIDGET]));
    $withAiAppended = [...$withoutAi, DASHBOARD_AI_WIDGET];
    $withAiMovedEarlier = $withAiAppended;
    $aiIndex = array_search(DASHBOARD_AI_WIDGET, $withAiMovedEarlier, true);

    if ($aiIndex !== false && $aiIndex > 0) {
        [$withAiMovedEarlier[$aiIndex - 1], $withAiMovedEarlier[$aiIndex]] = [
            $withAiMovedEarlier[$aiIndex],
            $withAiMovedEarlier[$aiIndex - 1],
        ];
    }

    $component = Livewire::actingAs($admin)->test(Index::class);

    $component->call('remove', DASHBOARD_AI_WIDGET);
    expect($admin->refresh()->prefsArray()[DashboardLayout::PREF_KEY])
        ->toBe($withoutAi);

    $component->call('add', DASHBOARD_AI_WIDGET);
    expect($admin->refresh()->prefsArray()[DashboardLayout::PREF_KEY])
        ->toBe($withAiAppended);

    $component->call('moveUp', DASHBOARD_AI_WIDGET);
    expect($admin->refresh()->prefsArray()[DashboardLayout::PREF_KEY])
        ->toBe($withAiMovedEarlier);

    $component->call('resetLayout');
    expect($admin->refresh()->prefsArray())
        ->not->toHaveKey(DashboardLayout::PREF_KEY);
});

it('skips stale or invisible widget ids in a saved layout silently', function (): void {
    $admin = createAdminUser();
    $admin->prefs = [DashboardLayout::PREF_KEY => ['bogus.widget', DASHBOARD_LEAVE_WIDGET]];
    $admin->save();

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
    $admin->prefs = [DashboardLayout::PREF_KEY => []];
    $admin->save();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertViewHas('widgets', fn (array $widgets): bool => $widgets === []);
});

it('never persists a widget id the user cannot see', function (): void {
    $user = createDashboardUser();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('add', DASHBOARD_LEAVE_WIDGET);

    expect($user->refresh()->prefsArray())
        ->not->toHaveKey(DashboardLayout::PREF_KEY);
});
