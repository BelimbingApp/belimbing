<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'system.database-tables',
            'label' => 'Database Tables',
            'icon' => 'heroicon-o-table-cells',
            'route' => 'admin.system.database-tables.index',
            'permission' => 'admin.system_table.list',
            'parent' => 'system',
        ],
        [
            'id' => 'system.database-queries',
            'label' => 'Database Queries',
            'icon' => 'heroicon-o-circle-stack',
            'route' => 'admin.system.database-queries.index',
            'permission' => 'admin.system_table.list',
            'parent' => 'system',
        ],
        [
            'id' => 'system.database-backups',
            'label' => 'Database Backups',
            'icon' => 'heroicon-o-archive-box',
            'route' => 'admin.system.database-backups.index',
            'permission' => 'admin.backup.list',
            'parent' => 'system',
        ],
    ],
];
