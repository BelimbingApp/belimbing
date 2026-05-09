<?php
return [
    'items' => [
        [
            'id' => 'admin.user',
            'label' => 'Users',
            'icon' => 'heroicon-o-users',
            'route' => 'admin.users.index',
            'permission' => 'admin.user.list',
            'parent' => 'admin',
        ],
    ],
];
