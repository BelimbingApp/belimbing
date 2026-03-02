<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'ai',
            'label' => 'AI',
            'icon' => 'heroicon-o-cpu-chip',
            'parent' => 'admin',
            'position' => 200,
        ],
        [
            'id' => 'ai.playground',
            'label' => 'DW Playground',
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'route' => 'admin.ai.playground',
            'parent' => 'ai',
            'position' => 10,
        ],
        [
            'id' => 'ai.providers',
            'label' => 'LLM Providers',
            'icon' => 'heroicon-o-server-stack',
            'route' => 'admin.ai.providers.index',
            'parent' => 'ai',
            'position' => 20,
        ],
    ],
];
