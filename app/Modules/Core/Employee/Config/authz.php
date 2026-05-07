<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'domains' => [
        'people' => 'People and employee administration.',
    ],

    'capabilities' => [
        'people.employee.view',
        'people.employee.list',
        'people.employee.create',
        'people.employee.update',
        'people.employee.delete',
        'people.employee-type.list',
        'people.employee-type.create',
        'people.employee-type.update',
        'people.employee-type.delete',
    ],

    'roles' => [
        'employee_viewer' => [
            'name' => 'Employee Viewer',
            'description' => 'Read-only access to employee, company, and address data.',
            'capabilities' => [
                'people.employee.view',
                'people.employee.list',
                'people.employee-type.list',
                'admin.company.view',
                'admin.company.list',
                'admin.address.view',
                'admin.address.list',
                'admin.geonames.view',
                'admin.geonames.list',
            ],
        ],
        'employee_editor' => [
            'name' => 'Employee Editor',
            'description' => 'Read-write access to employees, employee types, companies, and addresses.',
            'capabilities' => [
                'people.employee.view',
                'people.employee.list',
                'people.employee.create',
                'people.employee.update',
                'people.employee.delete',
                'people.employee-type.list',
                'people.employee-type.create',
                'people.employee-type.update',
                'people.employee-type.delete',
                'admin.company.view',
                'admin.company.list',
                'admin.company.create',
                'admin.company.update',
                'admin.company.delete',
                'admin.address.view',
                'admin.address.list',
                'admin.address.create',
                'admin.address.update',
                'admin.address.delete',
                'admin.geonames.view',
                'admin.geonames.list',
            ],
        ],
    ],
];
