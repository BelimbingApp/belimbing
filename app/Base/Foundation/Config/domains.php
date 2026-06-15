<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domain Catalog
    |--------------------------------------------------------------------------
    |
    | Official BelimbingApp domain repos installable from the Business Domains admin
    | screen. Keys are the PascalCase mount directory under app/Modules.
    | A fresh Belimbing clone ships Base + Core only; everything below is
    | opt-in per deployment.
    |
    */
    'catalog' => [
        'Commerce' => [
            'repo' => 'https://github.com/BelimbingApp/blb-commerce.git',
            'description' => 'Catalog, inventory, sales, and marketplace channels.',
        ],
        'Operation' => [
            'repo' => 'https://github.com/BelimbingApp/blb-operation.git',
            'description' => 'IT service desk and quality management (NCR, CAPA, SCAR).',
        ],
        'People' => [
            'repo' => 'https://github.com/BelimbingApp/blb-people.git',
            'description' => 'Employees, attendance, leave, claims, and payroll.',
        ],
    ],

];
