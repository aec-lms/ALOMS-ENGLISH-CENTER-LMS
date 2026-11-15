<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CmsWebsite\Http\Controllers\CmsController;

Route::middleware(['web', 'installed', 'auth', 'plugin:cms-website', 'permission:cms.manage'])
    ->prefix('admin/cms')
    ->name('plugins.cms.')
    ->group(function (): void {
        Route::get('/', [CmsController::class, 'index'])->name('index');

        // Blog Posts CRUD + Thumbnail Upload
        Route::post('/posts', [CmsController::class, 'storePost'])->name('posts.store');
        Route::put('/posts/{post}', [CmsController::class, 'updatePost'])->name('posts.update');
        Route::delete('/posts/{post}', [CmsController::class, 'destroyPost'])->name('posts.destroy');

        // Custom Pages CRUD + Banner Picture Upload
        Route::post('/pages', [CmsController::class, 'storePage'])->name('pages.store');
        Route::put('/pages/{page}', [CmsController::class, 'updatePage'])->name('pages.update');
        Route::delete('/pages/{page}', [CmsController::class, 'destroyPage'])->name('pages.destroy');

        // Sliders & Carousels CRUD + Picture Upload
        Route::post('/sliders', [CmsController::class, 'storeSlider'])->name('sliders.store');
        Route::put('/sliders/{slider}', [CmsController::class, 'updateSlider'])->name('sliders.update');
        Route::delete('/sliders/{slider}', [CmsController::class, 'destroySlider'])->name('sliders.destroy');

        // Homepage Customizer
        Route::post('/homepage', [CmsController::class, 'saveHomepage'])->name('homepage.save');
    });
