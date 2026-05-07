<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
