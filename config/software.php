<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment role
    |--------------------------------------------------------------------------
    |
    | The role this deployment instance declares for itself: 'development',
    | 'staging', or 'production'. Upstream synchronization (#339) is available
    | only on a deployment explicitly declared development or staging; unset
    | or unrecognised values fail closed. Deliberately a separate variable
    | from APP_ENV so a production install can never enable synchronization
    | by inheriting a development app config.
    |
    */

    'deployment_role' => env('BLB_DEPLOYMENT_ROLE'),

];
