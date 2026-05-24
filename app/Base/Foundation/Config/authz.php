<?php
return [
    'capabilities' => [
        // Plugin manager UI (admin/system/plugins). Read-only by design;
        // see docs/plans/plugin-manager-ui.md.
        'admin.system.plugins.view',
        'admin.system.plugins.manage',
    ],
];
