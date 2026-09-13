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

Route::get('/catalog/project', [
    ModpackController::class,
    'catalogProject',
]);

Route::get('/catalog/providers', [
    ModpackController::class,
    'catalogProviders',
]);

Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [
        ServerSubject::class,
        AuthenticateServerAccess::class,
        ResourceBelongsToServer::class,
    ],
], function () {
    Route::post('/preview', [
        ModpackController::class,
        'preview',
    ]);

    Route::post('/install', [
        ModpackController::class,
        'install',
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

    Route::post('/installed/{id}/uninstall', [
        ModpackController::class,
        'uninstall',
    ]);
});
