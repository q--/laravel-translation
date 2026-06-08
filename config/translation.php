<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Package driver
    |--------------------------------------------------------------------------
    |
    | The package supports different drivers for translation management.
    |
    | Supported: "file", "database"
    |
    */
    'driver' => 'file',

    /*
    |--------------------------------------------------------------------------
    | Route group configuration
    |--------------------------------------------------------------------------
    |
    | The package ships with routes to handle language management. Update the
    | configuration here to configure the routes with your preferred group options.
    |
    */
    'route_group_config' => [
        'middleware' => 'web',
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation methods
    |--------------------------------------------------------------------------
    |
    | Update this array to tell the package which methods it should look for
    | when finding missing translations.
    |
    */
    'translation_methods' => ['trans', '__'],

    /*
    |--------------------------------------------------------------------------
    | Scan paths
    |--------------------------------------------------------------------------
    |
    | Update this array to tell the package which directories to scan when
    | looking for missing translations.
    |
    */
    'scan_paths' => [app_path(), resource_path()],

    /*
    |--------------------------------------------------------------------------
    | UI URL
    |--------------------------------------------------------------------------
    |
    | Define the URL used to access the language management too.
    |
    */
    'ui_url' => 'languages',

    /*
    |--------------------------------------------------------------------------
    | Auto-translate settings
    |--------------------------------------------------------------------------
    |
    | Configure the parallel translation and proxy pool behaviour.
    |
    */
    'auto_translate' => [
        // Max languages translated simultaneously (each uses a separate proxy)
        'concurrency' => 10,

        // Path to the SQLite file that stores proxy state.
        // null = user-level default (~/.config/laravel-translation/proxies.sqlite)
        'proxy_store_path' => null,

        // Minimum number of working proxies to have before starting parallel work
        'proxy_min_pool' => 5,

        // Mark a proxy as dead after this many consecutive failures
        'proxy_fail_limit' => 3,

        // Timeout in seconds when testing or using a proxy
        'proxy_timeout_sec' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database settings
    |--------------------------------------------------------------------------
    |
    | Define the settings for the database driver here.
    |
    */
    'database' => [

        'connection' => '',

        'languages_table' => 'languages',

        'translations_table' => 'translations',
    ],
];
