<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

$readOnlyCapabilities = [
    'admin.employee.view',
    'admin.employee.list',
    'admin.employee-type.list',
    'admin.company.view',
    'admin.company.list',
    'admin.address.view',
    'admin.address.list',
    'admin.geonames.view',
    'admin.geonames.list',
];

$readWriteAdditionalCapabilities = [
    'admin.employee.create',
    'admin.employee.update',
    'admin.employee.delete',
    'admin.employee-type.create',
    'admin.employee-type.update',
    'admin.employee-type.delete',
    'admin.company.create',
    'admin.company.update',
    'admin.company.delete',
    'admin.address.create',
    'admin.address.update',
    'admin.address.delete',
];

return [
    'domains' => [
        'admin' => 'Employee and employee-type administration.',
    ],

    'capabilities' => [
        'admin.employee.view',
        'admin.employee.list',
        'admin.employee.create',
        'admin.employee.update',
        'admin.employee.delete',
        'admin.employee-type.list',
        'admin.employee-type.create',
        'admin.employee-type.update',
        'admin.employee-type.delete',
    ],

    'roles' => [
        'employee_viewer' => [
            'name' => 'Employee Viewer',
            'description' => 'Read-only access to employee, company, and address data.',
            'capabilities' => $readOnlyCapabilities,
        ],
        'employee_editor' => [
            'name' => 'Employee Editor',
            'description' => 'Read-write access to employees, employee types, companies, and addresses.',
            'capabilities' => [
                ...$readOnlyCapabilities,
                ...$readWriteAdditionalCapabilities,
            ],
        ],
    ],
];
