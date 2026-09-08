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
        //
        // People (#833 / blb-people#347) no longer needs entries — every
        // Domain command extends TenantScopedCommand. Connector entries stay
        // until blb-people-connector#249 / #275 lands.
        'allowlist' => [
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
