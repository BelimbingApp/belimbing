<?php

use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;

it('links the inactive Lara status-bar action to AI Providers with setup guidance', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('href="'.route('admin.ai.providers').'"', false)
        ->assertSee('title="Activate Lara"', false)
        ->assertSee('Activate Lara');
});

it('renders tagged diagnostics in the status bar detail surface', function (): void {
    $provider = new class implements StatusBarDiagnosticProvider
    {
        public function diagnosticsFor(Authenticatable $user): iterable
        {
            return [
                new StatusBarDiagnostic(
                    id: 'test.status-bar.warning',
                    severity: StatusVariant::Warning,
                    source: 'Menu',
                    summary: 'Synthetic warning',
                    detail: 'Diagnostic detail',
                    target: route('admin.system.menu-inspector.index'),
                ),
            ];
        }
    };

    $this->app->instance($provider::class, $provider);
    $this->app->tag([$provider::class], StatusBarDiagnosticProvider::CONTAINER_TAG);

    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('1 diagnostic')
        ->assertSee('Synthetic warning')
        ->assertSee('Diagnostic detail')
        ->assertSee('href="'.route('admin.system.menu-inspector.index').'"', false);
});
