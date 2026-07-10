<?php

$databaseParent = 'admin.system.database';
$databaseMenuItem = static fn (
    string $id,
    string $label,
    string $icon,
    string $route,
    string $permission,
): array => [
    'id' => $id,
    'label' => $label,
    'icon' => $icon,
    'route' => $route,
    'permission' => $permission,
    'parent' => $databaseParent,
];

return [
    'items' => [
        $databaseMenuItem(
            'admin.system.database-table',
            'Database Tables',
            'heroicon-o-table-cells',
            'admin.system.database-tables.index',
            'admin.system.database-table.list',
        ),
        $databaseMenuItem(
            'admin.system.database-incubation',
            'Schema Incubation',
            'heroicon-o-beaker',
            'admin.system.database-incubation.index',
            'admin.system.database-incubation.manage',
        ),
        $databaseMenuItem(
            'admin.system.database-query',
            'Database Queries',
            'heroicon-o-circle-stack',
            'admin.system.database-queries.index',
            'admin.system.database-table.list',
        ),
        $databaseMenuItem(
            'admin.system.database-backup',
            'Database Backups',
            'heroicon-o-archive-box',
            'admin.system.database-backups.index',
            'admin.system.database-backup.list',
        ),
        $databaseMenuItem(
            'admin.system.database-bridge',
            'Data Bridge',
            'heroicon-o-arrow-up-tray',
            'admin.system.database-bridge.index',
            'admin.system.database-bridge.view',
        ),
        $databaseMenuItem(
            'admin.system.database-residue',
            'Database Residue',
            'heroicon-o-trash',
            'admin.system.database-residue.index',
            'admin.system.database-residue.view',
        ),
    ],
];
