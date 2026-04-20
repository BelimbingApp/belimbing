<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

$item = static function (
    string $id,
    string $label,
    string $icon,
    string $parent,
    int $position,
    ?string $route = null,
    ?string $condition = null,
    ?string $permission = null,
): array {
    return array_filter([
        'id' => $id,
        'label' => $label,
        'icon' => $icon,
        'route' => $route,
        'parent' => $parent,
        'position' => $position,
        'condition' => $condition,
        'permission' => $permission,
    ], static fn (mixed $value): bool => $value !== null);
};

return [
    'items' => [
        $item('ai', 'AI', 'heroicon-o-cpu-chip', 'admin', 200),
        $item('ai.lara', 'Lara', 'heroicon-o-sparkles', 'ai', 10, 'admin.setup.lara', null, 'admin.ai_lara.manage'),
        $item('ai.task-models', 'Task Models', 'heroicon-o-adjustments-vertical', 'ai', 20, 'admin.ai.task-models', 'ai.lara_activated', 'admin.ai_task_model.manage'),
        $item('ai.providers', 'AI Providers', 'heroicon-o-server-stack', 'ai', 30, 'admin.ai.providers', null, 'admin.ai_provider.manage'),
        $item('ai.tools', 'Tools', 'heroicon-o-wrench-screwdriver', 'ai', 40, 'admin.ai.tools', null, 'admin.ai_tool.manage'),
        $item('ai.control-plane', 'Control Plane', 'heroicon-o-adjustments-horizontal', 'ai', 50, 'admin.ai.control-plane', null, 'admin.ai_control_plane.view'),
    ],
];
