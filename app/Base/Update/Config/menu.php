<?php

$item = require __DIR__.'/../../Menu/Config/item.php';

return [
    'items' => [
        $item('admin.system.update.deployment', 'Updates', 'heroicon-o-arrow-path', parent: 'admin.system.update', route: 'admin.system.update.deployment.index', permission: 'admin.system.update.deployment.manage'),
        $item('admin.system.update.github-access', 'GitHub Access', 'heroicon-o-key', parent: 'admin.system.update', route: 'admin.system.update.github-access.index', permission: 'admin.system.update.github-access.manage'),
    ],
];
