<?php

return [
    'editable' => [
        'software_deployment' => [
            'label' => 'Deployment',
            'capability' => 'admin.system.software.updates.manage',
            'description' => 'Environment role for Software Updates. Upstream synchronization stays unavailable until development or staging is set deliberately on a non-production installation.',
            'fields' => [
                [
                    'key' => 'software.deployment.role',
                    'label' => 'Deployment role',
                    'type' => 'select',
                    'scope' => 'global',
                    'default' => '',
                    'nullable' => true,
                    'options' => [
                        '' => 'Not set (synchronization unavailable)',
                        'development' => 'Development',
                        'staging' => 'Staging',
                        'production' => 'Production',
                    ],
                    'help' => 'Off by default. Production — and any installation whose APP_ENV is production — cannot synchronize with upstream. Read-only upstream visibility is unaffected.',
                    'rules' => ['nullable', 'string', 'in:development,staging,production'],
                ],
            ],
        ],
    ],

    // Operational history written by deployment/update services. These values
    // describe past runs; they are not operator-editable runtime parameters.
    'runtime' => [
        'system.update.composer.last_run',
        'system.update.frontend.last_run',
        'system.update.deployment.last_run',
        'system.update.frankenphp.last_reload',
        'system.update.frankenphp.reload_state',
    ],
];
