<?php

return [
    'tenant_context' => [
        'required_domains' => ['People', 'PeopleConnector'],

        // Route name => reason the route resolves and validates its tenant by
        // another reviewed boundary (for example, a signed external callback).
        // Blank reasons do not exempt a route.
        'exclusions' => [
            'people-connector.webhook' => 'Signed provider callback resolves its tenant from the verified connection before dispatch.',
        ],
    ],

    'middleware_audit' => [
        // Route name => reason the route is exempt from the tenant/authorization
        // middleware audit. Blank reasons do not exempt a route.
        'allowlist' => [
            'people-connector.webhook' => 'Signed provider webhook authenticates via connection signature rather than session authz.',
        ],
    ],
];
