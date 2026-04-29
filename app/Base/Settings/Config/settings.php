<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long resolved DB setting lookups are cached. Set to 0 to disable.
    |
    */
    'cache_ttl' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Cache Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all settings cache keys.
    |
    */
    'cache_prefix' => 'blb:settings',

    /*
    |--------------------------------------------------------------------------
    | Operator-Editable Settings
    |--------------------------------------------------------------------------
    |
    | Base Settings owns storage, scope resolution, encryption, discovery, and
    | generic rendering. Domain settings are declared by their owning modules in
    | Config/settings.php and discovered into this registry at boot.
    |
    */
    'editable' => [
    ],

];
