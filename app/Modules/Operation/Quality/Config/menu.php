<?php
return [
    'items' => [
        [
            'id' => 'operations.quality',
            'label' => 'Quality',
            'icon' => 'heroicon-o-shield-check',
            'parent' => 'operations',
        ],
        [
            'id' => 'operations.quality.ncr',
            'label' => 'NCR',
            'icon' => 'heroicon-o-flag',
            'route' => 'quality.ncr.index',
            'permission' => 'operations.quality.ncr.view',
            'parent' => 'operations.quality',
        ],
        [
            'id' => 'operations.quality.scar',
            'label' => 'SCAR',
            'icon' => 'heroicon-o-clipboard-document-check',
            'route' => 'quality.scar.index',
            'permission' => 'operations.quality.scar.view',
            'parent' => 'operations.quality',
        ],
    ],
];
