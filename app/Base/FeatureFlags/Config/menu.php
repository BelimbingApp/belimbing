<?php

$item = require __DIR__.'/../../Menu/Config/item.php';

return [
    'items' => [
        $item(
            'admin.system.feature-flags',
            'Feature Flags',
            'heroicon-o-flag',
            parent: 'admin.system',
            route: 'admin.system.feature-flags.index',
            permission: 'admin.system.feature-flags.view',
        ),
    ],
];
