<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'capabilities' => [
        'admin.system_table.list',
        'admin.system_table.view',
        'admin.system_table.edit',

        // Database backup admin UI (admin/system/backups).
        'admin.backup.list',
        'admin.backup.create',
        'admin.backup.delete',
    ],
];
