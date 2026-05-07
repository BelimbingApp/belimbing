<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'audit',
            'label' => 'Audit Log',
            'icon' => 'heroicon-o-document-magnifying-glass',
            'parent' => 'admin',
        ],
        [
            'id' => 'audit.mutations',
            'label' => 'Data Mutations',
            'icon' => 'heroicon-o-document-text',
            'route' => 'admin.audit.mutations',
            'permission' => 'admin.audit_log.list',
            'parent' => 'audit',
        ],
        [
            'id' => 'audit.actions',
            'label' => 'Actions',
            'icon' => 'heroicon-o-bolt',
            'route' => 'admin.audit.actions',
            'permission' => 'admin.audit_log.list',
            'parent' => 'audit',
        ],
    ],
];
