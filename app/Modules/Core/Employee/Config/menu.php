<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.employee',
            'label' => 'Employees',
            'icon' => 'heroicon-o-user-group',
            'route' => 'admin.employees.index',
            'permission' => 'admin.employee.list',
            'parent' => 'admin',
        ],
        [
            'id' => 'admin.employee-type',
            'label' => 'Employee Types',
            'icon' => 'heroicon-o-tag',
            'route' => 'admin.employee-types.index',
            'permission' => 'admin.employee-type.list',
            'parent' => 'admin.employee',
        ],
    ],
];
