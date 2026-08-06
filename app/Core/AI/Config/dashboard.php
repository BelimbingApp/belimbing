<?php

return [
    'widgets' => [
        [
            'id' => 'ai.operations-status',
            'label' => 'AI Operations',
            'description' => 'Dispatch ledger counts by status.',
            'icon' => 'heroicon-o-cpu-chip',
            'permission' => 'admin.ai.agent.view',
            'component' => 'ai.widgets.operations-status',
            'size' => 2,
        ],
    ],
];
