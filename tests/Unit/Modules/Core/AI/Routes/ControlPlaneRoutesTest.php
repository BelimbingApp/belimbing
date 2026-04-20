<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

it('registers authz middleware on AI admin routes', function (): void {
    $expectedMiddleware = [
        'admin.setup.lara' => 'authz:admin.ai_lara.manage',
        'admin.ai.task-models' => 'authz:admin.ai_task_model.manage',
        'admin.ai.playground' => 'authz:admin.ai_task_model.manage',
        'admin.ai.providers' => 'authz:admin.ai_provider.manage',
        'admin.ai.providers.setup' => 'authz:admin.ai_provider.manage',
        'admin.ai.providers.browse' => 'authz:admin.ai_provider.manage',
        'admin.ai.providers.connections' => 'authz:admin.ai_provider.manage',
        'admin.ai.tools' => 'authz:admin.ai_tool.manage',
        'admin.ai.control-plane' => 'authz:admin.ai_control_plane.view',
        'admin.ai.runs.show' => 'authz:admin.ai_control_plane.view',
    ];

    foreach ($expectedMiddleware as $routeName => $middleware) {
        $route = Route::getRoutes()->getByName($routeName);

        expect($route)->not->toBeNull();
        expect($route->gatherMiddleware())->toContain($middleware);
    }
});
