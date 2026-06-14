<?php

$item = require __DIR__.'/../../Menu/Config/item.php';

return [
    'items' => [
        $item('admin.system.update.belimbing', 'Belimbing', 'heroicon-o-arrow-path', parent: 'admin.system.update', route: 'admin.system.update.belimbing.index', permission: 'admin.system.update.belimbing.manage'),
        $item('admin.system.update.github-access', 'GitHub Access', 'heroicon-o-key', parent: 'admin.system.update', route: 'admin.system.update.github-access.index', permission: 'admin.system.update.github-access.manage'),
    ],
];
