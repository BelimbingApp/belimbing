<?php

return [
    'domains' => [
        'admin' => 'Administrative operations',
    ],

    'verbs' => [
        'view',
        'list',
        'create',
        'update',
        'delete',
        'submit',
        'approve',
        'reject',
        'execute',
        'impersonate',
        'manage',
        'grant',
        'revoke',
        'send',
        'react',
        'edit',
        'media',
        'poll',
        'search',
        'assign',
        'review',
        'triage',
        'respond',
        'verify',
        'close',
        'issue',
        'accept',
        'rework',
        'cancel',
        'upload',
        'follow_up',
        'hod_approve',
    ],

    // Capabilities owned by the base framework (no module to host them yet).
    // Module-owned capabilities live in each module's Config/authz.php
    // and are auto-discovered by App\Base\Authz\ServiceProvider.
    'capabilities' => [
        'admin.user.impersonate',
        'admin.role.list',
        'admin.role.view',
        'admin.role.create',
        'admin.role.update',
        'admin.role.delete',
        'admin.principal_role.list',
        'admin.capability.list',
        'admin.principal_capability.list',
        'admin.decision_log.list',
    ],

    'decision_log_retention_days' => 90,

    // System roles that aggregate capabilities across modules.
    // Module-scoped roles may also be declared in module Config/authz.php.
    'roles' => [
        'core_admin' => [
            'name' => 'Core Administrator',
            'description' => 'System role with all capabilities. New capabilities are automatically granted.',
            'grant_all' => true,
        ],
        'auditor' => [
            'name' => 'Auditor',
            'description' => 'Read-only access to decision logs, system logs, and sessions for compliance.',
            'capabilities' => [
                'admin.decision_log.list',
                'admin.system_log.list',
                'admin.system_session.list',
            ],
        ],
        'system_viewer' => [
            'name' => 'System Viewer',
            'description' => 'Read-only access to system infrastructure: tables, jobs, cache, scheduled tasks, and sessions.',
            'capabilities' => [
                'admin.system_table.list',
                'admin.system_table.view',
                'admin.system_log.list',
                'admin.system_failed_job.list',
                'admin.system_job_batch.list',
                'admin.system_scheduled_task.list',
                'admin.system_info.view',
                'admin.system_session.list',
                'admin.system_cache.view',
                'admin.system_transport_test.view',
                'admin.system_ui_reference.view',
            ],
        ],
    ],
];
