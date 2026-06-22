<?php

$item = require __DIR__.'/../../Menu/Config/item.php';

return [
    'items' => [
        $item('admin.system.software.deployment', 'Updates', 'heroicon-o-arrow-path', parent: 'admin.system.software', route: 'admin.system.software.deployment.index', permission: 'admin.system.software.deployment.manage'),
        $item('admin.system.software.github-access', 'GitHub Access', 'heroicon-o-key', parent: 'admin.system.software', route: 'admin.system.software.github-access.index', permission: 'admin.system.software.github-access.manage'),
    ],
];
