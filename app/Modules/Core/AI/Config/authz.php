<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'capabilities' => [
        // Grants access to Lara provisioning and activation.
        'admin.ai_lara.manage',
        // Grants access to Lara task-model selection and recommendations.
        'admin.ai_task_model.manage',
        // Grants access to provider catalog, credentials, and model sync flows.
        'admin.ai_provider.manage',
        // Grants access to AI tool catalog and per-tool workspaces.
        'admin.ai_tool.manage',
        // Grants access to the operator-facing AI diagnostics surfaces:
        // the control plane itself plus standalone run drill-down pages.
        'admin.ai_control_plane.view',
    ],

    'roles' => [
        'ai_operator' => [
            'name' => 'AI Operator',
            'description' => 'Access to Lara setup, task models, providers, tools, and operator diagnostics without broad system administration rights.',
            'capabilities' => [
                'admin.ai_lara.manage',
                'admin.ai_task_model.manage',
                'admin.ai_provider.manage',
                'admin.ai_tool.manage',
                'admin.ai_control_plane.view',
            ],
        ],
    ],
];
