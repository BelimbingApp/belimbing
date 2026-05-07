<?php

// Workflow module administrative capabilities.
// Process-specific transition capabilities (e.g., operations.quality.ncr.review)
// are declared by the owning business module, not here.

return [
    'domains' => [
        'workflow' => 'Workflow and state transitions',
    ],

    'capabilities' => [
        'admin.workflow.manage',
        'admin.workflow.status.manage',
        'admin.workflow.transition.manage',
    ],
];
