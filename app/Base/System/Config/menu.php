<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'system.info',
            'label' => 'System Info',
            'icon' => 'heroicon-o-information-circle',
            'route' => 'admin.system.info.index',
            'permission' => 'admin.system_info.view',
            'parent' => 'system',
            'position' => 90,
        ],
        [
            'id' => 'system.localization',
            'label' => 'Localization',
            'icon' => 'heroicon-o-language',
            'route' => 'admin.system.localization.index',
            'permission' => 'admin.system_localization.manage',
            'parent' => 'system',
            'position' => 95,
        ],
        [
            'id' => 'system.test-transport',
            'label' => 'TestTransport',
            'icon' => 'heroicon-o-play',
            'route' => 'admin.system.test-transport.index',
            'permission' => 'admin.system_transport_test.view',
            'parent' => 'system',
            'position' => 98,
        ],
    ],
];
