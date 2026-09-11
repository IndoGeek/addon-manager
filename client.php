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
});
