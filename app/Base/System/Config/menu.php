<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.system.info',
            'label' => 'System Info',
            'icon' => 'heroicon-o-information-circle',
            'route' => 'admin.system.info.index',
            'permission' => 'admin.system.info.view',
            'parent' => 'admin.system',
        ],
        [
            'id' => 'admin.system.localization',
            'label' => 'Localization',
            'icon' => 'heroicon-o-language',
            'route' => 'admin.system.localization.index',
            'permission' => 'admin.system.localization.manage',
            'parent' => 'admin.system',
        ],
        [
            'id' => 'admin.system.ui-reference',
            'label' => 'UI Reference',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'route' => 'admin.system.ui-reference.index',
            'permission' => 'admin.system.ui-reference.view',
            'parent' => 'admin.system',
        ],
        [
            'id' => 'admin.system.test-transport',
            'label' => 'Test Transport',
            'icon' => 'heroicon-o-play',
            'route' => 'admin.system.test-transport.index',
            'permission' => 'admin.system.test-transport.view',
            'parent' => 'admin.system.integrations',
        ],
        [
            'id' => 'admin.system.menu-inspector',
            'label' => 'Menu Inspector',
            'icon' => 'heroicon-o-magnifying-glass',
            'route' => 'admin.system.menu-inspector.index',
            'permission' => 'admin.system.menu-inspector.view',
            'parent' => 'admin.system.diagnostics',
        ],
    ],
];
