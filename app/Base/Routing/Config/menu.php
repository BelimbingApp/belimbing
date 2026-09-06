<?php

$item = require __DIR__.'/../../Menu/Config/item.php';

return [
    'items' => [
        $item(
            'admin.system.tenant-audit',
            'Tenant Audit',
            'heroicon-o-shield-exclamation',
            parent: 'admin.system.diagnostics',
            route: 'admin.system.tenant-audit.index',
            permission: 'admin.system.audit.view',
        ),
    ],
];
