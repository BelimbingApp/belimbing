<?php
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
