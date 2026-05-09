<?php
return [
    'items' => [
        [
            'id' => 'admin.workflow',
            'label' => 'Workflows',
            'icon' => 'heroicon-o-arrow-path',
            'route' => 'admin.workflows.index',
            'permission' => 'admin.workflow.manage',
            'parent' => 'admin',
        ],
    ],
];
