<?php

return [
    'capabilities' => [
        // Plugin manager UI (admin/system/plugins). Read-only by design;
        // see docs/plans/plugin-manager-ui.md.
        'admin.system.plugins.view',
        'admin.system.plugins.manage',

        // Domain manager UI (admin/system/domains). Code install/uninstall
        // stays in the shell; manage gates database-residue cleanup only.
        'admin.system.domains.view',
        'admin.system.domains.manage',
    ],
];
