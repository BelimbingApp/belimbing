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
        // as dated rollout exemptions only while the owning repository still
        // needs an upstream migration; the composed-smoke audit (#912) is not
        // proof that this allowlist is correct — see
        // DomainCommandTenantAudit::isAllowlisted().
        'allowlist' => [
            'people-connector:sync' => ['reason' => 'Temporary upstream gap: SyncWorkforceCommand still needs TenantScopedCommand in blb-people-connector; tracked in docs/plans/tenancy-people-connector-sync-command.md.', 'expires' => '2026-11-15'],
        ],
    ],
];
