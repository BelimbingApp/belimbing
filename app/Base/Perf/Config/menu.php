<?php

$item = require __DIR__.'/../../Menu/Config/item.php';

return [
    'items' => [
        $item('admin.system.perf', 'Performance', 'heroicon-o-bolt', parent: 'admin.system.diagnostics', route: 'admin.system.perf.index', permission: 'admin.system.perf.view'),
    ],
];
