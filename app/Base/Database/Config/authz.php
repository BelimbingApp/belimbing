<?php
return [
    'capabilities' => [
        'admin.system.database-table.list',
        'admin.system.database-table.view',
        'admin.system.database-table.edit',

        // Database backup admin UI (admin/system/backups).
        'admin.system.database-backup.list',
        'admin.system.database-backup.create',
        'admin.system.database-backup.delete',
        'admin.system.database-backup.manage',
    ],
];
