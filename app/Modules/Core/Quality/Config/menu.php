<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'quality',
            'label' => 'Quality',
            'icon' => 'heroicon-o-shield-check',
            'parent' => 'business',
            'position' => 200,
        ],
        [
            'id' => 'quality.ncr',
            'label' => 'NCR',
            'route' => 'quality.ncr.index',
            'permission' => 'quality.ncr.view',
            'parent' => 'quality',
            'position' => 100,
        ],
        [
            'id' => 'quality.scar',
            'label' => 'SCAR',
            'route' => 'quality.scar.index',
            'permission' => 'quality.scar.view',
            'parent' => 'quality',
            'position' => 200,
        ],
    ],
];
