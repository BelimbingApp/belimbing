<?php

return [
    // Operational history written by deployment/update services. These values
    // describe past runs; they are not operator-editable runtime parameters.
    'runtime' => [
        'system.update.composer.last_run',
        'system.update.frontend.last_run',
        'system.update.deployment.last_run',
        'system.update.frankenphp.last_reload',
        'system.update.frankenphp.reload_state',
    ],
];
