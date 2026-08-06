<?php
return [
    'items' => [
        [
            'id' => 'admin.address',
            'label' => 'Addresses',
            'icon' => 'heroicon-o-map-pin',
            'route' => 'admin.addresses.index',
            'permission' => 'admin.address.list',
            'parent' => 'admin',
        ],
    ],
];
