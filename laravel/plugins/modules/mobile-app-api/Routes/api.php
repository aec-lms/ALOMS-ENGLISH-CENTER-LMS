<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\MobileAppApi\Http\Controllers\MobileApiController;

Route::middleware(['api', 'installed', 'plugin:mobile-app-api'])
    ->prefix('api/mobile')
    ->name('plugins.mobile.')
    ->group(function (): void {
        Route::post('/login', [MobileApiController::class, 'login'])->name('login');
        Route::get('/courses', [MobileApiController::class, 'courses'])->name('courses');
        Route::get('/me', [MobileApiController::class, 'me'])->middleware('auth:sanctum')->name('me');
    });
