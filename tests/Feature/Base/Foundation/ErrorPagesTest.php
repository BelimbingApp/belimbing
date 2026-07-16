<?php

use App\Base\Software\Services\DeploymentMaintenanceGuard;
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
        Artisan::call('up');
    }
});
