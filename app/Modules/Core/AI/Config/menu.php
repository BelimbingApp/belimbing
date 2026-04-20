<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

$item = static function (array $item): array {
    return array_filter($item, static fn (mixed $value): bool => $value !== null);
};

return [
    'items' => [
        $item([
            'id' => 'ai',
            'label' => 'AI',
            'icon' => 'heroicon-o-cpu-chip',
            'parent' => 'admin',
            'position' => 200,
        ]),
        $item([
            'id' => 'ai.lara',
            'label' => 'Lara',
            'icon' => 'heroicon-o-sparkles',
            'route' => 'admin.setup.lara',
            'parent' => 'ai',
            'position' => 10,
            'permission' => 'admin.ai_lara.manage',
        ]),
        $item([
            'id' => 'ai.task-models',
            'label' => 'Task Models',
            'icon' => 'heroicon-o-adjustments-vertical',
            'route' => 'admin.ai.task-models',
            'parent' => 'ai',
            'position' => 20,
            'condition' => 'ai.lara_activated',
            'permission' => 'admin.ai_task_model.manage',
        ]),
        $item([
            'id' => 'ai.providers',
            'label' => 'AI Providers',
            'icon' => 'heroicon-o-server-stack',
            'route' => 'admin.ai.providers',
            'parent' => 'ai',
            'position' => 30,
            'permission' => 'admin.ai_provider.manage',
        ]),
        $item([
            'id' => 'ai.tools',
            'label' => 'Tools',
            'icon' => 'heroicon-o-wrench-screwdriver',
            'route' => 'admin.ai.tools',
            'parent' => 'ai',
            'position' => 40,
            'permission' => 'admin.ai_tool.manage',
        ]),
        $item([
            'id' => 'ai.control-plane',
            'label' => 'Control Plane',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'route' => 'admin.ai.control-plane',
            'parent' => 'ai',
            'position' => 50,
            'permission' => 'admin.ai_control_plane.view',
        ]),
    ],
];
