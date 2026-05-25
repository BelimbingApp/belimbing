<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | BLB framework views live under resources/core/views. Pluggable module
    | views live with their module under app/Modules/{Domain}/{Module}/Views
    | or extensions/{owner}/{module}/Views and are registered by that module's
    | ServiceProvider with loadViewsFrom.
    |
    */

    'paths' => [
        resource_path('core/views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | This option determines where all the compiled Blade templates will be
    | stored for your application. Typically, this is within the storage
    | directory. However, as usual, you are free to change this value.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),

];
