<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'system.scheduled-tasks',
            'label' => 'Scheduled Tasks',
            'icon' => 'heroicon-o-clock',
            'route' => 'admin.system.scheduled-tasks.index',
            'permission' => 'admin.system_scheduled_task.list',
            'parent' => 'system.diagnostics',
        ],
    ],
];
