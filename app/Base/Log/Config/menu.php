<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'system.logs',
            'label' => 'Logs',
            'icon' => 'heroicon-o-document-text',
            'route' => 'admin.system.logs.index',
            'permission' => 'admin.system_log.list',
            'parent' => 'system',
        ],
    ],
];
