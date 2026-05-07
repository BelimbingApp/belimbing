<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'ai',
            'label' => 'AI',
            'icon' => 'heroicon-o-cpu-chip',
            'parent' => 'admin',
        ],
        [
            'id' => 'ai.lara',
            'label' => 'Lara',
            'icon' => 'heroicon-o-sparkles',
            'route' => 'admin.setup.lara',
            'parent' => 'ai',
            'permission' => 'admin.ai_lara.manage',
        ],
        [
            'id' => 'ai.task-models',
            'label' => 'Task Models',
            'icon' => 'heroicon-o-adjustments-vertical',
            'route' => 'admin.ai.task-models',
            'parent' => 'ai',
            'condition' => 'ai.lara_activated',
            'permission' => 'admin.ai_task_model.manage',
        ],
        [
            'id' => 'ai.providers',
            'label' => 'AI Providers',
            'icon' => 'heroicon-o-server-stack',
            'route' => 'admin.ai.providers',
            'parent' => 'ai',
            'permission' => 'admin.ai_provider.manage',
        ],
        [
            'id' => 'ai.tools',
            'label' => 'Tools',
            'icon' => 'heroicon-o-wrench-screwdriver',
            'route' => 'admin.ai.tools',
            'parent' => 'ai',
            'permission' => 'admin.ai_tool.manage',
        ],
        [
            'id' => 'ai.pricing-overrides',
            'label' => 'Pricing Overrides',
            'icon' => 'heroicon-o-banknotes',
            'route' => 'admin.ai.pricing-overrides',
            'parent' => 'ai',
            'permission' => 'admin.ai_pricing_override.manage',
        ],
        [
            'id' => 'ai.control-plane',
            'label' => 'Control Plane',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'route' => 'admin.ai.control-plane',
            'parent' => 'ai',
            'permission' => 'admin.ai_control_plane.view',
        ],
    ],
];
