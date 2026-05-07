<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.employees',
            'label' => 'Employees',
            'icon' => 'heroicon-o-user-group',
            'route' => 'admin.employees.index',
            'permission' => 'core.employee.list',
            'parent' => 'people',
        ],
        [
            'id' => 'admin.employee-types',
            'label' => 'Employee Types',
            'icon' => 'heroicon-o-tag',
            'route' => 'admin.employee-types.index',
            'permission' => 'core.employee_type.list',
            'parent' => 'admin.employees',
        ],
    ],
];
