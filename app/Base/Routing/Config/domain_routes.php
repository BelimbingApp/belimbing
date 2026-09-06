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
];
