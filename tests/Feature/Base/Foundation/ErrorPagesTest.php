<?php

use App\Base\Software\Services\DeploymentMaintenanceGuard;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

it('offers try again instead of a self-referential back to home when the error happened on home', function (): void {
    Route::get('/', fn () => abort(500))->middleware([]);

    $this->get('/')
        ->assertStatus(500)
        ->assertSee('Try again')
        ->assertDontSee('Back to home');
});

it('offers back to home normally when the error happened elsewhere', function (): void {
    Route::get('/elsewhere-broken', fn () => abort(500))->middleware([]);

    $this->get('/elsewhere-broken')
        ->assertStatus(500)
        ->assertSee('Back to home');
});

it('drops the session-expired secondary home link when already home', function (): void {
    Route::get('/', fn () => abort(419))->middleware([]);

    $html = $this->get('/')->assertStatus(419)->getContent();

    expect($html)->not->toContain('class="quiet"');
});

// The bug this guards: a 404 for an unmatched URL is thrown during routing,
// before the web middleware group runs, so the session never loads and the
// error view always renders as a guest — even for a signed-in user. Handling
// unmatched GETs via a fallback route inside the web group is what starts the
// session, so assert the resolved route is that fallback and carries the group.
it('routes unmatched GET URLs through a web-group fallback so the session loads', function (): void {
    $route = app('router')->getRoutes()->match(
        Request::create('/definitely-not-a-real-page', 'GET'),
    );

    expect($route->isFallback)->toBeTrue()
        ->and($route->middleware())->toContain('web');
});

it('renders an authenticated 404 inside the app shell exactly once', function (): void {
    $html = $this->actingAs(createAdminUser())
        ->get('/authenticated-not-found-xyz')
        ->assertNotFound()
        ->assertSee('404', false)
        ->assertSee(__('Page not found'), false)
        ->assertSee(__('Toggle sidebar'), false)
        ->getContent();

    // @extends is an unconditional compile-time footer, so wrapping it in a
    // runtime @guest check once painted the standalone document *and* the
    // app-shell document together. A single <!DOCTYPE> proves one 404 rendered.
    expect(substr_count($html, '<!DOCTYPE html>'))->toBe(1);

    // The in-shell 404 is a dead end for navigation, so it carries no redundant
    // "Back to home" action and no pin affordance (the page is not pinnable).
    expect($html)->not->toContain(__('Back to home'))
        ->and($html)->not->toContain(__('Pin to sidebar'));
});

it('renders a guest 404 on the standalone error layout', function (): void {
    $html = $this->get('/guest-not-found-xyz')
        ->assertNotFound()
        ->assertSee(__('Page not found'), false)
        ->assertSee(__('Back to home'), false)
        ->assertDontSee(__('Toggle sidebar'), false)
        ->getContent();

    expect(substr_count($html, __('Back to home')))->toBe(1);
});

it('returns a JSON 404 for unmatched URLs when the client expects JSON', function (): void {
    $this->getJson('/definitely-not-a-real-api-endpoint')
        ->assertNotFound()
        ->assertExactJson(['message' => __('Not Found.')]);
});

it('renders a tenantless boundary failure as a standalone 404 page', function (): void {
    Route::get('/tenant-context-required', fn () => app(TenantContext::class)->requireTenantId())
        ->middleware(['web', 'auth']);
    $user = User::factory()->create(['company_id' => null]);

    $this->actingAs($user)
        ->get('/tenant-context-required')
        ->assertNotFound()
        ->assertSee(__('Page not found'), false)
        ->assertDontSee(__('Toggle sidebar'), false);
});

it('returns a JSON 404 for a tenantless boundary failure', function (): void {
    Route::get('/tenant-context-required-json', fn () => app(TenantContext::class)->requireTenantId())
        ->middleware(['web', 'auth']);
    $user = User::factory()->create(['company_id' => null]);

    $this->actingAs($user)
        ->getJson('/tenant-context-required-json')
        ->assertNotFound()
        ->assertJson(['reason_code' => 'tenant_context_missing']);
});

it('renders a self-retrying maintenance page for manual downtime', function (): void {
    Artisan::call('down', ['--retry' => 5]);

    try {
        $this->get('/')
            ->assertStatus(503)
            ->assertSee(__('Down for maintenance'))
            ->assertSee('http-equiv="refresh"', false);
    } finally {
        Artisan::call('up');
    }
});

it('tells users an update is installing when the update owns maintenance mode', function (): void {
    $maintenance = app(DeploymentMaintenanceGuard::class);
    // A live lease is what makes the run current rather than abandoned wreckage;
    // without it the page must fall back to generic maintenance copy (below).
    $writeLease = new ReflectionMethod($maintenance, 'writeLease');
    $writeLease->invoke($maintenance, 'test-run', true);
    Artisan::call('down', ['--retry' => 5]);

    try {
        // Stamp the payload the way DeploymentMaintenanceGuard::enter() does.
        $mode = app()->maintenanceMode();
        $mode->activate(array_merge($mode->data(), [
            DeploymentMaintenanceGuard::MAINTENANCE_DATA_RUN_ID => 'test-run',
        ]));

        $this->get('/')
            ->assertStatus(503)
            ->assertSee(__('Installing an update'))
            ->assertSee('http-equiv="refresh"', false)
            ->assertDontSee(__('Down for maintenance'));
    } finally {
        $maintenance->disarm('test-run');
        Artisan::call('up');
    }
});

test('the maintenance page falls back to planned work copy when the update lease is stale', function (): void {
    Artisan::call('down', ['--retry' => 5]);

    try {
        $mode = app()->maintenanceMode();
        $mode->activate(array_merge($mode->data(), [
            DeploymentMaintenanceGuard::MAINTENANCE_DATA_RUN_ID => 'test-run',
        ]));

        $this->get('/')
            ->assertStatus(503)
            ->assertSee(__('Down for maintenance'))
            ->assertDontSee(__('Installing an update'));
    } finally {
        Artisan::call('up');
    }
});

test('a stranded maintenance page points an operator at the console that can lift it', function (): void {
    // Run id stamped, lease gone: an update went down holding maintenance and is
    // never coming back to lift it. The Updates console is maintenance-excepted
    // and carries "Bring back online", so the 503 offers it rather than leaving a
    // shell as the only way out.
    Artisan::call('down', ['--retry' => 5]);

    try {
        $mode = app()->maintenanceMode();
        $mode->activate(array_merge($mode->data(), [
            DeploymentMaintenanceGuard::MAINTENANCE_DATA_RUN_ID => 'test-run',
        ]));

        $this->get('/')
            ->assertStatus(503)
            ->assertSee(__('Administrator: bring the site back online'))
            ->assertSee(route('admin.system.software.updates.index'), false);
    } finally {
        Artisan::call('up');
    }
});

test('the maintenance page offers no recovery link when nothing is stranded', function (): void {
    // Manual downtime carries no run id, and a running update lifts its own hold —
    // neither is stranded, so neither should invite an operator to override it.
    $maintenance = app(DeploymentMaintenanceGuard::class);
    $writeLease = new ReflectionMethod($maintenance, 'writeLease');
    $writeLease->invoke($maintenance, 'test-run', true);
    Artisan::call('down', ['--retry' => 5]);

    try {
        $this->get('/')
            ->assertStatus(503)
            ->assertDontSee(__('Administrator: bring the site back online'));

        $mode = app()->maintenanceMode();
        $mode->activate(array_merge($mode->data(), [
            DeploymentMaintenanceGuard::MAINTENANCE_DATA_RUN_ID => 'test-run',
        ]));

        $this->get('/')
            ->assertStatus(503)
            ->assertDontSee(__('Administrator: bring the site back online'));
    } finally {
        $maintenance->disarm('test-run');
        Artisan::call('up');
    }
});
