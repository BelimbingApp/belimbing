<?php
return [
    'items' => [
        [
            'id' => 'admin.system.database-table',
            'label' => 'Database Tables',
            'icon' => 'heroicon-o-table-cells',
            'route' => 'admin.system.database-tables.index',
            'permission' => 'admin.system.database-table.list',
            'parent' => 'admin.system.database',
        ],
        [
            'id' => 'admin.system.database-query',
            'label' => 'Database Queries',
            'icon' => 'heroicon-o-circle-stack',
            'route' => 'admin.system.database-queries.index',
            'permission' => 'admin.system.database-table.list',
            'parent' => 'admin.system.database',
        ],
        [
            'id' => 'admin.system.database-backup',
            'label' => 'Database Backups',
            'icon' => 'heroicon-o-archive-box',
            'route' => 'admin.system.database-backups.index',
            'permission' => 'admin.system.database-backup.list',
            'parent' => 'admin.system.database',
        ],
    ],
];
