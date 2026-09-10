<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\ModpackController;

Route::get('/metadata', [ModpackController::class, 'metadata']);
