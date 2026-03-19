<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'it',
            'label' => 'IT',
            'icon' => 'heroicon-o-computer-desktop',
            'parent' => 'business',
            'position' => 100,
        ],
        [
            'id' => 'it.tickets',
            'label' => 'Tickets',
            'icon' => 'heroicon-o-ticket',
            'route' => 'it.tickets.index',
            'permission' => 'it_ticket.ticket.list',
            'parent' => 'it',
            'position' => 100,
        ],
    ],
];
