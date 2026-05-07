<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'authz',
            'label' => 'Authorization',
            'icon' => 'heroicon-o-shield-check',
            'parent' => 'admin',
        ],
        [
            'id' => 'authz.capabilities',
            'label' => 'Capabilities',
            'icon' => 'heroicon-o-puzzle-piece',
            'route' => 'admin.authz.capabilities.index',
            'permission' => 'admin.capability.list',
            'parent' => 'authz',
        ],
        [
            'id' => 'authz.roles',
            'label' => 'Roles',
            'icon' => 'heroicon-o-shield-check',
            'route' => 'admin.roles.index',
            'permission' => 'admin.role.list',
            'parent' => 'authz',
        ],
        [
            'id' => 'authz.principal-roles',
            'label' => 'Principal Roles',
            'icon' => 'heroicon-o-user-circle',
            'route' => 'admin.authz.principal-roles.index',
            'permission' => 'admin.principal_role.list',
            'parent' => 'authz',
        ],
        [
            'id' => 'authz.principal-capabilities',
            'label' => 'Principal Capabilities',
            'icon' => 'heroicon-o-key',
            'route' => 'admin.authz.principal-capabilities.index',
            'permission' => 'admin.principal_capability.list',
            'parent' => 'authz',
        ],
        [
            'id' => 'authz.decision-logs',
            'label' => 'Decision Logs',
            'icon' => 'heroicon-o-clipboard-document-list',
            'route' => 'admin.authz.decision-logs.index',
            'permission' => 'admin.decision_log.list',
            'parent' => 'authz',
        ],
    ],
];
