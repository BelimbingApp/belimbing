<?php
return [
    'domains' => [
        'core' => 'Core platform modules',
    ],

    'capabilities' => [
        'admin.user.view',
        'admin.user.list',
        'admin.user.create',
        'admin.user.update',
        'admin.user.delete',
    ],

    'roles' => [
        'user_viewer' => [
            'name' => 'User Viewer',
            'description' => 'Read-only access to user management.',
            'capabilities' => [
                'admin.user.list',
                'admin.user.view',
            ],
        ],
        'user_editor' => [
            'name' => 'User Editor',
            'description' => 'Read-write access to user management.',
            'capabilities' => [
                'admin.user.list',
                'admin.user.view',
                'admin.user.create',
                'admin.user.update',
                'admin.user.delete',
            ],
        ],
    ],
];
