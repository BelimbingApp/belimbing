<?php

use App\Base\Settings\Services\DatabaseSettingsService;

return [

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long resolved DB setting lookups are cached. Set to 0 to disable.
    |
    */
    'cache_ttl' => DatabaseSettingsService::DEFAULT_CACHE_TTL_SECONDS,

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
    | Runtime Parameter Definitions
    |--------------------------------------------------------------------------
    |
    | Module-owned definitions are discovered from Config/settings.php files.
    | Each definition owns its type, allowed scopes, default, validation, and
    | encryption policy. Runtime consumers resolve these keys through Settings.
    |
    */
    'definitions' => [],

    /*
    |--------------------------------------------------------------------------
    | Runtime State Claims
    |--------------------------------------------------------------------------
    |
    | Module-owned operational state keys and wildcard namespaces. Claims are
    | deliberately separate from runtime parameter definitions: they establish
    | ownership but do not provide defaults, validation, or editable metadata.
    |
    */
    'runtime' => [],

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
