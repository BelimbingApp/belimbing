<?php

use App\Base\Routing\RouteDiscoveryService;
use Illuminate\Support\Facades\File;

const DOMAIN_ROUTE_INVENTORY_FIXTURE = 'app/Domains/ZzRouteInventory/Fixture';

function writeDomainRouteInventoryFixture(): array
{
    $root = base_path(DOMAIN_ROUTE_INVENTORY_FIXTURE.'/Routes');
    File::ensureDirectoryExists($root);

    $web = $root.'/web.php';
    $api = $root.'/api.php';

    File::put($web, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->get('zz-route-inventory/employees/{employee}', fn () => 'ok')
    ->name('zz-route-inventory.employee.show');
PHP);

    File::put($api, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')
    ->post('zz-route-inventory/employees', fn () => 'ok')
    ->name('zz-route-inventory.employee.store');
PHP);

    return [$web, $api];
}

it('prints a stable JSON inventory of every route in a mounted domain module', function (): void {
    [$web, $api] = writeDomainRouteInventoryFixture();

    try {
        app(RouteDiscoveryService::class)->registerRoutes([
            'web' => [$web],
            'api' => [$api],
        ]);

        $this->artisan('blb:domain-routes', ['--json' => true])
            ->expectsOutput(json_encode([
                [
                    'domain' => 'ZzRouteInventory',
                    'module' => 'Fixture',
                    'uri' => 'api/zz-route-inventory/employees',
                    'name' => 'zz-route-inventory.employee.store',
                    'methods' => ['POST'],
                    'middleware' => ['api', 'throttle:api'],
                ],
                [
                    'domain' => 'ZzRouteInventory',
                    'module' => 'Fixture',
                    'uri' => 'zz-route-inventory/employees/{employee}',
                    'name' => 'zz-route-inventory.employee.show',
                    'methods' => ['GET', 'HEAD'],
                    'middleware' => ['web', 'auth', 'verified'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->assertSuccessful();
    } finally {
        File::deleteDirectory(base_path('app/Domains/ZzRouteInventory'));
    }
});
