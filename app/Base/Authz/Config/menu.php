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

    return $row;
};

return [
    'items' => [
        $item('admin.authz', 'Authorization', 'heroicon-o-shield-check', parent: 'admin'),
        $item('admin.authz.capability', 'Capabilities', 'heroicon-o-puzzle-piece', parent: 'admin.authz', route: 'admin.authz.capabilities.index', permission: 'admin.authz.capability.list'),
        $item('admin.authz.role', 'Roles', 'heroicon-o-shield-check', parent: 'admin.authz', route: 'admin.roles.index', permission: 'admin.authz.role.list'),
        $item('admin.authz.principal-role', 'Principal Roles', 'heroicon-o-user-circle', parent: 'admin.authz', route: 'admin.authz.principal-roles.index', permission: 'admin.authz.principal-role.list'),
        $item('admin.authz.principal-capability', 'Principal Capabilities', 'heroicon-o-key', parent: 'admin.authz', route: 'admin.authz.principal-capabilities.index', permission: 'admin.authz.principal-capability.list'),
        $item('admin.authz.decision-log', 'Decision Logs', 'heroicon-o-clipboard-document-list', parent: 'admin.authz', route: 'admin.authz.decision-logs.index', permission: 'admin.authz.decision-log.list'),
    ],
];
