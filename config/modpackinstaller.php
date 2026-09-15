<?php

/*
 * Extension env values. Reading these through the Laravel config repository
 * (rather than calling env() directly in app code) keeps the extension
 * working on panels that run `php artisan config:cache`: once config is
 * cached, env() returns null for every call made outside a config file, so
 * every value must be mapped here and re-cached after it changes in .env.
 */

return [
    'curseforge_api_key' => env('CURSEFORGE_API_KEY'),

    'max_download_mb' => env('MODPACK_INSTALLER_MAX_DOWNLOAD_MB'),

    'server_target' => env('MODPACK_INSTALLER_SERVER_TARGET', 'local'),

    'server_root' => env('MODPACK_INSTALLER_SERVER_ROOT'),

    'data_dir' => env('MODPACK_INSTALLER_DATA_DIR'),
];