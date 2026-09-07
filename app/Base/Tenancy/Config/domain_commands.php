<?php

return [
    'tenant_scope' => [
        // Domains whose Artisan commands must extend TenantScopedCommand
        // (or carry a dated allowlist entry). Mirrors domain_routes.
        'required_domains' => ['People', 'PeopleConnector'],

        // Artisan command name => ['reason' => why the command may skip
        // TenantScopedCommand, 'expires' => YYYY-MM-DD]. A blank reason or a
        // missing or past expiry does not exempt a command (#710): the audit
        // goes red on the day the exemption lapses.
        'allowlist' => [
            'blb:attendance:policy:simulate' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'blb:attendance:policy:validate' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'blb:attendance:roster' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'blb:claim:policy:simulate' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'blb:claim:policy:validate' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'blb:leave:carry-forward' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'blb:leave:expire-replacement' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'blb:leave:seed-sbg-pack' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'blb:payroll:materialize-pending' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'people:performance:overdue' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'people:reminders-due' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'people:skills-workbook-dry-run' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'people:training:effectiveness-due' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people#316.', 'expires' => '2026-10-07'],
            'connector:capability:verify' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'connector:doctor' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'connector:health:check' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'connector:identity-import' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'connector:identity:audit-trail' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'connector:webhook:dead-letters' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'connector:webhook:replay' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'people-connector:cutover-rehearsal' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'people-connector:retention-purge' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'people-connector:retention-report' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'people-connector:subject-export' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
            'people-connector:sync' => ['reason' => 'Rollout exemption: migrated onto TenantScopedCommand in blb-people-connector#249.', 'expires' => '2026-10-07'],
        ],
    ],
];
