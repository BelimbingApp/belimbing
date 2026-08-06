<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

it('registers authz middleware on AI admin routes', function (): void {
    $expectedMiddleware = [
        'admin.setup.lara' => 'authz:admin.ai.lara.manage',
        'admin.ai.task-models' => 'authz:admin.ai.task-model.manage',
        'admin.ai.providers' => 'authz:admin.ai.provider.manage',
        'admin.ai.providers.setup' => 'authz:admin.ai.provider.manage',
        'admin.ai.tools' => 'authz:admin.ai.tool.manage',
        'admin.ai.control-plane' => 'authz:admin.ai.control-plane.view',
        'admin.ai.runs.show' => 'authz:admin.ai.control-plane.view',
    ];

    foreach ($expectedMiddleware as $routeName => $middleware) {
        $route = Route::getRoutes()->getByName($routeName);

        expect($route)->not->toBeNull();
        expect($route->gatherMiddleware())->toContain($middleware);
    }
});
