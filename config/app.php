<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => 'Belimbing',

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    | When not set, derived from APP_SCHEME and FRONTEND_DOMAIN.
    | All instances share Caddy on :443, so the port is always standard.
    |
    */

    'url' => env('APP_URL') ?: (
        (env('APP_SCHEME') ?: 'https').'://'.
        (env('FRONTEND_DOMAIN') ?: 'local.blb.lara')
    ),

    /*
    |--------------------------------------------------------------------------
    | Git Executable
    |--------------------------------------------------------------------------
    |
    | Long-running app servers, especially Windows services, may not inherit the
    | same PATH as the setup shell. Pinning the executable keeps deployment and
    | module-management git checks honest even when PATH differs.
    |
    */

    'git_executable' => env('BLB_GIT_EXECUTABLE') ?: env('GIT_EXECUTABLE'),

    'caddy_server_admin_host' => env('CADDY_SERVER_ADMIN_HOST'),

    'caddy_server_admin_port' => env('CADDY_SERVER_ADMIN_PORT'),

    'licensee_company_name' => env('LICENSEE_COMPANY_NAME'),

    'licensee_company_code' => env('LICENSEE_COMPANY_CODE'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    // Translation selection uses a language code. Regional formatting comes
    // from the declared ui.locale setting through LocaleContext.
    'locale' => 'en',

    'fallback_locale' => 'en',

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Warm on Boot
    |--------------------------------------------------------------------------
    |
    | When enabled, the application will warm commonly used caches during
    | application boot. This can improve response times for the first request
    | after deployment but may slow down boot time.
    |
    */

    'cache_warm_on_boot' => env('APP_CACHE_WARM_ON_BOOT', false),

];
