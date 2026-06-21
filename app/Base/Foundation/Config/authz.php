<?php

return [
    'capabilities' => [
        // Bundle manager UI (admin/system/bundles). Read-only by design;
        // see docs/plans/plugin-manager-ui.md.
        'admin.system.bundles.view',
        'admin.system.bundles.manage',

        // Business domain manager UI (admin/system/update/business-domains).
        // Code install/uninstall stays in the shell; manage gates the
        // lifecycle actions while durable-state cleanup stays in Database.
        'admin.system.update.business-domain.view',
        'admin.system.update.business-domain.manage',
    ],
];
