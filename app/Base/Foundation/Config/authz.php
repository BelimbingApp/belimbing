<?php

return [
    'capabilities' => [
        // Bundle manager UI (admin/system/software/bundles). Read-only by design;
        // see docs/plans/plugin-manager-ui.md.
        'admin.system.software.bundles.view',
        'admin.system.software.bundles.manage',

        // Business domain manager UI (admin/system/software/business-domains).
        // Code install/uninstall stays in the shell; manage gates the
        // lifecycle actions while durable-state cleanup stays in Database.
        'admin.system.software.business-domain.view',
        'admin.system.software.business-domain.manage',
    ],
];
