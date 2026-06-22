<?php

$item = require __DIR__.'/../../Menu/Config/item.php';

return [
    'items' => [
        $item('admin.system.software.updates', 'Updates', 'heroicon-o-arrow-path', parent: 'admin.system.software', route: 'admin.system.software.updates.index', permission: 'admin.system.software.updates.manage'),
        $item('admin.system.software.github-access', 'GitHub Access', 'heroicon-o-key', parent: 'admin.system.software', route: 'admin.system.software.github-access.index', permission: 'admin.system.software.github-access.manage'),
    ],
];
