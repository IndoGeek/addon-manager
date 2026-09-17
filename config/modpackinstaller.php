<?php

// Extension env values.

return [
    'curseforge_api_key' => env('CURSEFORGE_API_KEY'),

    'max_download_mb' => env('MODPACK_INSTALLER_MAX_DOWNLOAD_MB'),

    'server_target' => env('MODPACK_INSTALLER_SERVER_TARGET', 'local'),

    'server_root' => env('MODPACK_INSTALLER_SERVER_ROOT'),

    'data_dir' => env('MODPACK_INSTALLER_DATA_DIR'),

    // | Lifetime in seconds of cached upstream catalog responses (search, | version lists, project details) stored in the...
    'catalog_cache_ttl' => env('MODPACK_INSTALLER_CATALOG_CACHE_TTL', 300),
];