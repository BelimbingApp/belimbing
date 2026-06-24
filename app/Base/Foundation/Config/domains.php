<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domain Catalog
    |--------------------------------------------------------------------------
    |
    | Official BelimbingApp add-in domain repos installable from the Software >
    | Modules admin screen. Keys are the PascalCase mount directory under
    | app/Modules. A fresh Belimbing clone ships the Platform Baseline
    | (Base + Core); everything below is opt-in per deployment.
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
