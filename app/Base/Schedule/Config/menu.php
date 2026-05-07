<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.system.scheduled-task',
            'label' => 'Scheduled Tasks',
            'icon' => 'heroicon-o-clock',
            'route' => 'admin.system.scheduled-tasks.index',
            'permission' => 'admin.system.scheduled-task.list',
            'parent' => 'admin.system.diagnostics',
        ],
    ],
];
