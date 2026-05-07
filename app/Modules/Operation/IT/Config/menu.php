<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'operations.it',
            'label' => 'IT',
            'icon' => 'heroicon-o-computer-desktop',
            'parent' => 'operations',
        ],
        [
            'id' => 'operations.it.ticket',
            'label' => 'Tickets',
            'icon' => 'heroicon-o-ticket',
            'route' => 'it.tickets.index',
            'permission' => 'operations.it.ticket.list',
            'parent' => 'operations.it',
        ],
    ],
];
