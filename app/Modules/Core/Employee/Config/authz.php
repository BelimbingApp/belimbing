<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'domains' => [
        'core' => 'Core platform modules',
    ],

    'capabilities' => [
        'core.employee.view',
        'core.employee.list',
        'core.employee.create',
        'core.employee.update',
        'core.employee.delete',
        'core.employee_type.list',
        'core.employee_type.create',
        'core.employee_type.update',
        'core.employee_type.delete',
    ],

    'roles' => [
        'employee_viewer' => [
            'name' => 'Employee Viewer',
            'description' => 'Read-only access to employee, company, and address data.',
            'capabilities' => [
                'core.employee.view',
                'core.employee.list',
                'core.employee_type.list',
                'core.company.view',
                'core.company.list',
                'core.address.view',
                'core.address.list',
                'core.geonames.view',
                'core.geonames.list',
            ],
        ],
        'employee_editor' => [
            'name' => 'Employee Editor',
            'description' => 'Read-write access to employees, employee types, companies, and addresses.',
            'capabilities' => [
                'core.employee.view',
                'core.employee.list',
                'core.employee.create',
                'core.employee.update',
                'core.employee.delete',
                'core.employee_type.list',
                'core.employee_type.create',
                'core.employee_type.update',
                'core.employee_type.delete',
                'core.company.view',
                'core.company.list',
                'core.company.create',
                'core.company.update',
                'core.company.delete',
                'core.address.view',
                'core.address.list',
                'core.address.create',
                'core.address.update',
                'core.address.delete',
                'core.geonames.view',
                'core.geonames.list',
            ],
        ],
    ],
];
