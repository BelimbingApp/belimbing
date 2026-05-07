<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.workflow',
            'label' => 'Workflows',
            'icon' => 'heroicon-o-arrow-path',
            'route' => 'admin.workflows.index',
            'permission' => 'admin.workflow.manage',
            'parent' => 'admin',
        ],
    ],
];
