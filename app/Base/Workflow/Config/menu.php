<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.workflows',
            'label' => 'Workflows',
            'icon' => 'heroicon-o-arrow-path',
            'route' => 'admin.workflows.index',
            'permission' => 'workflow.process.manage',
            'parent' => 'admin',
            'position' => 220,
        ],
    ],
];
