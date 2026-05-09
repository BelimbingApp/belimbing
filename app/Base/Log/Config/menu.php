<?php
return [
    'items' => [
        [
            'id' => 'admin.system.log',
            'label' => 'Logs',
            'icon' => 'heroicon-o-document-text',
            'route' => 'admin.system.logs.index',
            'permission' => 'admin.system.log.list',
            'parent' => 'admin.system.diagnostics',
        ],
    ],
];
