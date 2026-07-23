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

        // Data share admin UI (admin/system/data-share).
        // create gates diagnostic row capture from the table browser.
        'admin.system.data-share.view',
        'admin.system.data-share.create',
        'admin.system.data-share.delete',
        'admin.system.data-share-offer.create',
        'admin.system.data-share-offer.manage',
        'admin.system.data-share-offer.accept',
        'admin.system.data-share-plan.review',
        'admin.system.data-share-apply.execute',
        'admin.system.data-share-mirror.execute',
        'admin.system.data-share-settings.manage',

        // Central data operations history (admin/system/data-operations).
        'admin.system.data-operations.view',
    ],
];
