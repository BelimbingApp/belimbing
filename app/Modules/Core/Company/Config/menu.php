<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.companies',
            'label' => 'Companies',
            'icon' => 'heroicon-o-building-office',
            'route' => 'admin.companies.index',
            'permission' => 'core.company.list',
            'parent' => 'admin',
        ],
        [
            'id' => 'admin.companies.legal-entity-types',
            'label' => 'Legal Entity Types',
            'icon' => 'heroicon-o-scale',
            'route' => 'admin.companies.legal-entity-types',
            'parent' => 'admin.companies',
        ],
        [
            'id' => 'admin.companies.department-types',
            'label' => 'Department Types',
            'icon' => 'heroicon-o-rectangle-group',
            'route' => 'admin.companies.department-types',
            'parent' => 'admin.companies',
        ],
    ],
];
