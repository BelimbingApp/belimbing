<?php

return [
    'domains' => [
        'admin' => 'Administrative operations',
    ],

    'verbs' => [
        'view',
        'view-team',
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

        /*
         * Directional external-provider ports (#779).
         *
         * `read` is this installation pulling records from a provider; `write`
         * is pushing them back. They are not the CRUD pair and must not be
         * used as one. `view` is a person looking at a record in the
         * interface, bounded by that person's company and audience; `read` is
         * a port draining a provider on nobody's behalf in particular, bounded
         * by nothing but this grant. Keeping them apart is what stops "may see
         * an employee" from becoming "may siphon the employee table out of the
         * vendor system".
         *
         * The verb is the LAST segment of a capability key, so a port
         * capability reads domain.resource.read — for example
         * people-connector.workforce-port.read. A key with the direction in
         * the middle parses its last segment as the verb and is rejected.
         */
        'read',
        'write',

        /*
         * Connector actions (#787).
         *
         * Each names something the installation does to a record it holds on a
         * provider's behalf: an identity is audited, exported or imported;
         * retention purges; support breaks glass. All five shipped as
         * capability keys before the verbs existed, so the catalog dropped
         * them and every check against them was denied — the features were
         * unreachable for everybody, and nothing in this repository's CI could
         * see it, because /app/Domains/* is not mounted here.
         *
         * `break-glass` is the deliberate, logged use of an emergency access
         * path. It is a verb because breaking glass is a thing somebody does,
         * not a thing somebody is.
         */
        'audit',
        'export',
        'import',
        'purge',
        'break-glass',
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
                'admin.address.view',
                'admin.address.list',
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
                'admin.system.capabilities.view',
            ],
        ],
        'system_viewer' => [
            'name' => 'System Viewer',
            'description' => 'Read-only access to system infrastructure: tables, jobs, cache, schedule, and sessions.',
            'capabilities' => [
                'admin.system.database-table.list',
                'admin.system.database-table.view',
                'admin.system.log.list',
                'admin.system.failed-job.list',
                'admin.system.capabilities.view',
                'admin.system.job-batch.list',
                'admin.system.schedule.view',
                'admin.system.info.view',
                'admin.system.session.list',
                'admin.system.cache.view',
                'admin.system.test-transport.view',
                'admin.system.ui-reference.view',
            ],
        ],
    ],
];
