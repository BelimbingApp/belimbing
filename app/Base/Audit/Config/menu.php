<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.audit',
            'label' => 'Audit Log',
            'icon' => 'heroicon-o-document-magnifying-glass',
            'parent' => 'admin',
        ],
        [
            'id' => 'admin.audit.mutation',
            'label' => 'Data Mutations',
            'icon' => 'heroicon-o-document-text',
            'route' => 'admin.audit.mutations',
            'permission' => 'admin.audit.log.list',
            'parent' => 'admin.audit',
        ],
        [
            'id' => 'admin.audit.action',
            'label' => 'Actions',
            'icon' => 'heroicon-o-bolt',
            'route' => 'admin.audit.actions',
            'permission' => 'admin.audit.log.list',
            'parent' => 'admin.audit',
        ],
    ],
];
