<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\ModpackController;

Route::get('/metadata', [
    ModpackController::class,
    'metadata',
]);

Route::get('/catalog', [
    ModpackController::class,
    'catalog',
]);

Route::get('/catalog/versions', [
    ModpackController::class,
    'catalogVersions',
]);

// Per-version loader/Minecraft-version options + dependency recommendations for single-file content (mods, plug...
Route::get('/catalog/mod-versions', [
    ModpackController::class,
    'catalogModVersions',
]);

Route::get('/catalog/description', [
    ModpackController::class,
    'catalogDescription',
]);

Route::get('/catalog/project', [
    ModpackController::class,
    'catalogProject',
]);

Route::get('/catalog/providers', [
    ModpackController::class,
    'catalogProviders',
]);

// Every install route is scoped to a server the caller can reach, and each controller action enforces the matching panel file permission.
Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [
        ServerSubject::class,
        AuthenticateServerAccess::class,
        ResourceBelongsToServer::class,
    ],
], function () {
    Route::post('/install', [
        ModpackController::class,
        'install',
    ]);

    // Single-file content: download one file and place it in the directory of its content type (mods, plugins, worl...
    Route::post('/install/mod', [
        ModpackController::class,
        'installContent',
    ]);

    Route::post('/install/cancel', [
        ModpackController::class,
        'cancelInstall',
    ]);

    // Progress is read through the owning server so a token minted for one server is never resolvable through another.
    Route::get('/install/progress', [
        ModpackController::class,
        'installProgress',
    ]);

    Route::get('/installed', [
        ModpackController::class,
        'installedModpacks',
    ]);

    Route::get('/installed/{id}', [
        ModpackController::class,
        'installedModpack',
    ]);

    Route::post('/installed/{id}/update', [
        ModpackController::class,
        'updateModpack',
    ]);

    Route::post('/installed/{id}/restore', [
        ModpackController::class,
        'restoreModpack',
    ]);

    Route::post('/installed/{id}/uninstall', [
        ModpackController::class,
        'uninstall',
    ]);
});
