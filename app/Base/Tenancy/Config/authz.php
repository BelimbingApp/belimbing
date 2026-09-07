<?php

return [
    'capabilities' => [
        'admin.tenancy.tenant.list',
        'admin.tenancy.tenant.create',
        // Change an existing tenant's status from the operator surface.
        'admin.tenancy.tenant.manage',
    ],
];
