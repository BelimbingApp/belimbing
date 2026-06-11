<?php

return [
    'capabilities' => [
        'admin.system.database-table.list',
        'admin.system.database-table.view',
        'admin.system.database-table.edit',
        'admin.system.database-incubation.manage',

        // Database backup admin UI (admin/system/backups).
        'admin.system.database-backup.list',
        'admin.system.database-backup.create',
        'admin.system.database-backup.delete',
        'admin.system.database-backup.manage',

        // Database residue admin UI (admin/system/database-residue).
        // manage gates the destructive cleanup of unclaimed DB state.
        'admin.system.database-residue.view',
        'admin.system.database-residue.manage',
    ],
];
