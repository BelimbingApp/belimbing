<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.ai',
            'label' => 'AI',
            'icon' => 'heroicon-o-cpu-chip',
            'parent' => 'admin',
        ],
        [
            'id' => 'admin.ai.lara',
            'label' => 'Lara',
            'icon' => 'heroicon-o-sparkles',
            'route' => 'admin.setup.lara',
            'parent' => 'admin.ai',
            'permission' => 'admin.ai.lara.manage',
        ],
        [
            'id' => 'admin.ai.task-model',
            'label' => 'Task Models',
            'icon' => 'heroicon-o-adjustments-vertical',
            'route' => 'admin.ai.task-models',
            'parent' => 'admin.ai',
            'condition' => 'ai.lara_activated',
            'permission' => 'admin.ai.task-model.manage',
        ],
        [
            'id' => 'admin.ai.provider',
            'label' => 'AI Providers',
            'icon' => 'heroicon-o-server-stack',
            'route' => 'admin.ai.providers',
            'parent' => 'admin.ai',
            'permission' => 'admin.ai.provider.manage',
        ],
        [
            'id' => 'admin.ai.tool',
            'label' => 'Tools',
            'icon' => 'heroicon-o-wrench-screwdriver',
            'route' => 'admin.ai.tools',
            'parent' => 'admin.ai',
            'permission' => 'admin.ai.tool.manage',
        ],
        [
            'id' => 'admin.ai.pricing-override',
            'label' => 'Pricing Overrides',
            'icon' => 'heroicon-o-banknotes',
            'route' => 'admin.ai.pricing-overrides',
            'parent' => 'admin.ai',
            'permission' => 'admin.ai.pricing-override.manage',
        ],
        [
            'id' => 'admin.ai.control-plane',
            'label' => 'Control Plane',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'route' => 'admin.ai.control-plane',
            'parent' => 'admin.ai',
            'permission' => 'admin.ai.control-plane.view',
        ],
    ],
];
