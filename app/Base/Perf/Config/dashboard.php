<?php

return [
    'widgets' => [
        [
            'id' => 'perf.request-health',
            'label' => 'Performance',
            'description' => 'Last 24 h of request latency and any routes regressing against their weekly baseline.',
            'icon' => 'heroicon-o-bolt',
            'permission' => 'admin.system.perf.view',
            'component' => 'perf.widgets.request-health',
            'size' => 2,
        ],
    ],
];
