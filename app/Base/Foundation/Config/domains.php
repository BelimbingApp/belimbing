<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domain Owners
    |--------------------------------------------------------------------------
    |
    | Installable Domains are discovered, not listed: the platform asks GitHub
    | for repositories carrying the `blb-domain` topic, in the organisation
    | this checkout's own git remotes point at — `origin` first, then
    | `upstream`. Set this only when the checkout has no usable remote (an
    | archive, an air-gapped install) or to search somewhere else entirely.
    | Accepts an array or a comma-separated string. See app/Domains/AGENTS.md.
    |
    */
    'owners' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BLB_DOMAIN_OWNERS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Catalog Cache Lifetime
    |--------------------------------------------------------------------------
    |
    | Hours before the discovered catalog is looked up again. GitHub being
    | unreachable must not make the install screen wait on every render.
    |
    */
    'catalog_ttl_hours' => (int) env('BLB_DOMAIN_CATALOG_TTL_HOURS', 24),

];
