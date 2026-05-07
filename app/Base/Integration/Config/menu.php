<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'system.integration-outbound-exchanges',
            'label' => 'Outbound Exchanges',
            'icon' => 'heroicon-o-arrow-top-right-on-square',
            'route' => 'admin.integration.outbound-exchanges.index',
            'permission' => 'admin.integration_exchange.list',
            'parent' => 'system',
        ],
    ],
];
