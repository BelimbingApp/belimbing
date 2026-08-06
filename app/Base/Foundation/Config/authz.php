<?php

return [
    'capabilities' => [
        // Domains screen (admin/system/software/domains) — installed software
        // inventory + domain lifecycle + BelimbingApp catalog. `view` reads the
        // screen; `manage` gates install/enable/disable/uninstall and catalog
        // refresh. Durable-state cleanup stays in Database (Database Residue).
        'admin.system.software.domains.view',
        'admin.system.software.domains.manage',
    ],
];
