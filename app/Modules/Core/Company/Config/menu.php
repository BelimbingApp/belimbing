<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.company',
            'label' => 'Companies',
            'icon' => 'heroicon-o-building-office',
            'route' => 'admin.companies.index',
            'permission' => 'admin.company.list',
            'parent' => 'admin',
        ],
        [
            'id' => 'admin.company.legal-entity-type',
            'label' => 'Legal Entity Types',
            'icon' => 'heroicon-o-scale',
            'route' => 'admin.companies.legal-entity-types',
            'parent' => 'admin.company',
        ],
        [
            'id' => 'admin.company.department-type',
            'label' => 'Department Types',
            'icon' => 'heroicon-o-rectangle-group',
            'route' => 'admin.companies.department-types',
            'parent' => 'admin.company',
        ],
    ],
];
