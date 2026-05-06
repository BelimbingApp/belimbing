<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'domains' => [
        'core' => 'Core platform modules',
    ],

    'capabilities' => [
        'core.user.view',
        'core.user.list',
        'core.user.create',
        'core.user.update',
        'core.user.delete',
    ],

    'roles' => [
        'user_viewer' => [
            'name' => 'User Viewer',
            'description' => 'Read-only access to user management.',
            'capabilities' => [
                'core.user.list',
                'core.user.view',
            ],
        ],
        'user_editor' => [
            'name' => 'User Editor',
            'description' => 'Read-write access to user management.',
            'capabilities' => [
                'core.user.list',
                'core.user.view',
                'core.user.create',
                'core.user.update',
                'core.user.delete',
            ],
        ],
    ],
];
