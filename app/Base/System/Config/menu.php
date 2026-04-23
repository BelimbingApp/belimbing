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
            'id' => 'system.ui-reference',
            'label' => 'UI Reference',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'route' => 'admin.system.ui-reference.index',
            'permission' => 'admin.system_ui_reference.view',
            'parent' => 'system',
            'position' => 97,
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
