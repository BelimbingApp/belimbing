<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'domains' => [
        'it_ticket' => 'IT support ticket management',
        'workflow' => 'Workflow and state transitions',
    ],

    'capabilities' => [
        'workflow.it_ticket.assign',
        'it_ticket.ticket.create',
        'it_ticket.ticket.view',
        'it_ticket.ticket.list',
    ],
];
