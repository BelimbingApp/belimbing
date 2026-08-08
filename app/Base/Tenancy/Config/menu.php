<?php

return [
    'items' => [
        [
            'id' => 'admin.tenancy',
            'label' => 'Tenancy',
            'icon' => 'heroicon-o-building-library',
            'parent' => 'admin',
            'condition' => 'tenancy.visible',
        ],
        [
            'id' => 'admin.tenancy.tenant',
            'label' => 'Tenants',
            'icon' => 'heroicon-o-building-office-2',
            'route' => 'admin.tenancy.tenants',
            'permission' => 'admin.tenancy.tenant.list',
            'parent' => 'admin.tenancy',
            'condition' => 'tenancy.visible',
        ],
    ],
];
