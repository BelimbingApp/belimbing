<?php

return [
    'tenant_scope' => [
        // Domains whose Artisan commands must extend TenantScopedCommand
        // (or carry a non-empty allowlist reason). Mirrors domain_routes.
        'required_domains' => ['People', 'PeopleConnector'],

        // Artisan command name => reason the Domain command may skip TenantScopedCommand.
        // Blank reasons do not exempt a command.
        'allowlist' => [
            'blb:attendance:policy:simulate' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'blb:attendance:policy:validate' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'blb:attendance:roster' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'blb:claim:policy:simulate' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'blb:claim:policy:validate' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'blb:leave:carry-forward' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'blb:leave:expire-replacement' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'blb:leave:seed-sbg-pack' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'blb:payroll:materialize-pending' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'people:reminders-due' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'people-connector:cutover-rehearsal' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'people-connector:retention-purge' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'people-connector:retention-report' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
            'people-connector:sync' => 'Rollout exemption: Domain command not yet migrated onto TenantScopedCommand.',
        ],
    ],
];
