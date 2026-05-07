<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.authz',
            'label' => 'Authorization',
            'icon' => 'heroicon-o-shield-check',
            'parent' => 'admin',
        ],
        [
            'id' => 'admin.authz.capability',
            'label' => 'Capabilities',
            'icon' => 'heroicon-o-puzzle-piece',
            'route' => 'admin.authz.capabilities.index',
            'permission' => 'admin.authz.capability.list',
            'parent' => 'admin.authz',
        ],
        [
            'id' => 'admin.authz.role',
            'label' => 'Roles',
            'icon' => 'heroicon-o-shield-check',
            'route' => 'admin.roles.index',
            'permission' => 'admin.authz.role.list',
            'parent' => 'admin.authz',
        ],
        [
            'id' => 'admin.authz.principal-role',
            'label' => 'Principal Roles',
            'icon' => 'heroicon-o-user-circle',
            'route' => 'admin.authz.principal-roles.index',
            'permission' => 'admin.authz.principal-role.list',
            'parent' => 'admin.authz',
        ],
        [
            'id' => 'admin.authz.principal-capability',
            'label' => 'Principal Capabilities',
            'icon' => 'heroicon-o-key',
            'route' => 'admin.authz.principal-capabilities.index',
            'permission' => 'admin.authz.principal-capability.list',
            'parent' => 'admin.authz',
        ],
        [
            'id' => 'admin.authz.decision-log',
            'label' => 'Decision Logs',
            'icon' => 'heroicon-o-clipboard-document-list',
            'route' => 'admin.authz.decision-logs.index',
            'permission' => 'admin.authz.decision-log.list',
            'parent' => 'admin.authz',
        ],
    ],
];
