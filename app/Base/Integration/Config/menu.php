<?php
return [
    'items' => [
        [
            'id' => 'admin.system.outbound-exchange',
            'label' => 'Outbound Exchanges',
            'icon' => 'heroicon-o-arrow-top-right-on-square',
            'route' => 'admin.integration.outbound-exchanges.index',
            'permission' => 'admin.system.outbound-exchange.list',
            'parent' => 'admin.system.integrations',
        ],
    ],
];
