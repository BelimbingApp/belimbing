<?php
return [
    'items' => [
        [
            'id' => 'admin.system.session',
            'label' => 'Sessions',
            'icon' => 'heroicon-o-finger-print',
            'route' => 'admin.system.sessions.index',
            'permission' => 'admin.system.session.list',
            'parent' => 'admin.system.diagnostics',
        ],
    ],
];
