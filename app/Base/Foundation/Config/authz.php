<?php

return [
    'capabilities' => [
        // Plugin manager UI (admin/system/plugins). Read-only by design;
        // see docs/plans/plugin-manager-ui.md.
        'admin.system.plugins.view',
        'admin.system.plugins.manage',

        // Business domain manager UI (admin/system/update/business-domains).
        // Code install/uninstall stays in the shell; manage gates the
        // lifecycle actions while durable-state cleanup stays in Database.
        'admin.system.update.business-domain.view',
        'admin.system.update.business-domain.manage',
    ],
];
