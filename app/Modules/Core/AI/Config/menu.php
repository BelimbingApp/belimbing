<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

$item = static function (
    string $id,
    string $label,
    string $icon,
    ?string $parent = null,
    ?string $route = null,
    ?string $permission = null,
    ?string $condition = null,
): array {
    $row = [
        'id' => $id,
        'label' => $label,
        'icon' => $icon,
    ];

    if ($parent !== null) {
        $row['parent'] = $parent;
    }

    if ($route !== null) {
        $row['route'] = $route;
    }

    if ($permission !== null) {
        $row['permission'] = $permission;
    }

    if ($condition !== null) {
        $row['condition'] = $condition;
    }

    return $row;
};

return [
    'items' => [
        $item('admin.ai', 'AI', 'heroicon-o-cpu-chip', parent: 'admin'),
        $item('admin.ai.lara', 'Lara', 'heroicon-o-sparkles', parent: 'admin.ai', route: 'admin.setup.lara', permission: 'admin.ai.lara.manage'),
        $item('admin.ai.task-model', 'Task Models', 'heroicon-o-adjustments-vertical', parent: 'admin.ai', route: 'admin.ai.task-models', permission: 'admin.ai.task-model.manage', condition: 'ai.lara_activated'),
        $item('admin.ai.provider', 'AI Providers', 'heroicon-o-server-stack', parent: 'admin.ai', route: 'admin.ai.providers', permission: 'admin.ai.provider.manage'),
        $item('admin.ai.tool', 'Tools', 'heroicon-o-wrench-screwdriver', parent: 'admin.ai', route: 'admin.ai.tools', permission: 'admin.ai.tool.manage'),
        $item('admin.ai.pricing-override', 'Pricing Overrides', 'heroicon-o-banknotes', parent: 'admin.ai', route: 'admin.ai.pricing-overrides', permission: 'admin.ai.pricing-override.manage'),
        $item('admin.ai.control-plane', 'Control Plane', 'heroicon-o-adjustments-horizontal', parent: 'admin.ai', route: 'admin.ai.control-plane', permission: 'admin.ai.control-plane.view'),
    ],
];
