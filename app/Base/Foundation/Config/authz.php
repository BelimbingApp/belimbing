<?php

return [
    'capabilities' => [
        // Modules screen (admin/system/software/modules) — installed software
        // inventory + domain lifecycle + BelimbingApp catalog. `view` reads the
        // screen; `manage` gates install/enable/disable/uninstall and catalog
        // refresh. Durable-state cleanup stays in Database (Database Residue).
        'admin.system.software.modules.view',
        'admin.system.software.modules.manage',
    ],
];
