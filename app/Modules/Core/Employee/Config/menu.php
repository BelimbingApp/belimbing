<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'people.employee',
            'label' => 'Employees',
            'icon' => 'heroicon-o-user-group',
            'route' => 'admin.employees.index',
            'permission' => 'people.employee.list',
            'parent' => 'people',
        ],
        [
            'id' => 'people.employee-type',
            'label' => 'Employee Types',
            'icon' => 'heroicon-o-tag',
            'route' => 'admin.employee-types.index',
            'permission' => 'people.employee-type.list',
            'parent' => 'people.employee',
        ],
    ],
];
