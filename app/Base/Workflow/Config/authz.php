<?php

// Workflow module administrative capabilities.
// Process-specific transition capabilities (e.g., workflow.leave_application.approve)
// are declared by the owning business module, not here.

return [
    'capabilities' => [
        'workflow.process.manage',
        'workflow.status.manage',
        'workflow.transition.manage',
    ],
];
