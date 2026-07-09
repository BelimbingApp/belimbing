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
        'unlock',
        'upload',
        'follow-up',
        'hod-approve',
    ],

    // Capabilities owned by the base framework (no module to host them yet).
    // Module-owned capabilities live in each module's Config/authz.php
    // and are auto-discovered by App\Base\Authz\ServiceProvider.
    'capabilities' => [
        'admin.user.impersonate',
        'admin.authz.role.list',
        'admin.authz.role.view',
        'admin.authz.role.create',
        'admin.authz.role.update',
        'admin.authz.role.delete',
        'admin.authz.principal-role.list',
        'admin.authz.capability.list',
        'admin.authz.principal-capability.list',
        'admin.authz.decision-log.list',
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
        'tenant_owner' => [
            'name' => 'Tenant Owner',
            'description' => 'Full control within a single tenant: commerce, AI, messaging, company, employees, and addresses. No platform administration.',
            'capabilities' => [
                // Commerce capabilities are contributed by the Commerce domain when installed.
                // Tenant self-management (company/employee data is scoped to the tenant by UI)
                'admin.company.view',
                'admin.company.list',
                'admin.company.update',
                'admin.employee.view',
                'admin.employee.list',
                'admin.employee.create',
                'admin.employee.update',
                'admin.employee.delete',
                'admin.employee-type.list',
                'admin.address.create',
                'admin.address.update',
                'admin.address.delete',
                'admin.geonames.view',
                'admin.geonames.list',
            ],
        ],
        'auditor' => [
            'name' => 'Auditor',
            'description' => 'Read-only access to decision logs, system logs, and sessions for compliance.',
            'capabilities' => [
                'admin.authz.decision-log.list',
                'admin.system.log.list',
                'admin.system.session.list',
            ],
        ],
        'system_viewer' => [
            'name' => 'System Viewer',
            'description' => 'Read-only access to system infrastructure: tables, jobs, cache, scheduled tasks, and sessions.',
            'capabilities' => [
                'admin.system.database-table.list',
                'admin.system.database-table.view',
                'admin.system.log.list',
                'admin.system.failed-job.list',
                'admin.system.job-batch.list',
                'admin.system.scheduled-task.list',
                'admin.system.info.view',
                'admin.system.session.list',
                'admin.system.cache.view',
                'admin.system.test-transport.view',
                'admin.system.ui-reference.view',
            ],
        ],
    ],
];
