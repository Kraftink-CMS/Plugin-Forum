<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Forum\Http\Controllers\Admin\AutomationController;
use KraftInk\Plugins\Forum\Http\Controllers\Admin\ForumAdminController;

Route::prefix('admin/plugins/forum')
    ->name('plugins.forum.admin.')
    ->middleware(['web', 'auth', 'permission:admin.access'])
    ->group(function () {
        Route::prefix('categories')
            ->name('categories.')
            ->group(function () {
                Route::get('/', [ForumAdminController::class, 'categories'])->name('index');
                Route::post('/', [ForumAdminController::class, 'saveCategory'])->name('store');
                Route::put('/{id}', [ForumAdminController::class, 'saveCategory'])
                    ->whereNumber('id')
                    ->name('update');
                Route::patch('/{id}/placement', [ForumAdminController::class, 'placeCategory'])
                    ->whereNumber('id')
                    ->name('placement.update');
                Route::delete('/{id}', [ForumAdminController::class, 'deleteCategory'])
                    ->whereNumber('id')
                    ->name('destroy');
            });

        Route::prefix('announcements')
            ->name('announcements.')
            ->group(function () {
                Route::get('/', [AutomationController::class, 'index'])->name('index');
                Route::post('/', [AutomationController::class, 'store'])->name('store');
                Route::post('/{id}/retry', [AutomationController::class, 'retry'])
                    ->whereUuid('id')
                    ->name('retry');
            });

        Route::prefix('preferences')
            ->name('settings.')
            ->group(function () {
                Route::post('/', [ForumAdminController::class, 'preferences'])->name('update');
            });

        Route::prefix('topics')
            ->name('topics.')
            ->group(function () {
                Route::get('/', [ForumAdminController::class, 'topics'])->name('index');
                Route::post('/', [ForumAdminController::class, 'createTopic'])->name('store');
                Route::patch('/{id}', [ForumAdminController::class, 'moderate'])
                    ->whereNumber('id')
                    ->name('update');
                Route::delete('/{id}', [ForumAdminController::class, 'deleteTopic'])
                    ->whereNumber('id')
                    ->name('destroy');
            });

        Route::prefix('reports')
            ->name('reports.')
            ->group(function () {
                Route::get('/', [ForumAdminController::class, 'reports'])->name('index');
                Route::post('/{id}/resolve', [ForumAdminController::class, 'resolveReport'])
                    ->whereNumber('id')
                    ->name('resolve');
            });

        Route::prefix('posts')
            ->name('posts.')
            ->group(function () {
                Route::patch('/{id}', [ForumAdminController::class, 'moderatePost'])
                    ->whereNumber('id')
                    ->name('update');
            });

        Route::prefix('tags')
            ->name('tags.')
            ->group(function () {
                Route::get('/', [ForumAdminController::class, 'tags'])->name('index');
                Route::post('/{id?}', [ForumAdminController::class, 'saveTag'])
                    ->whereNumber('id')
                    ->name('store');
                Route::delete('/{id}', [ForumAdminController::class, 'deleteTag'])
                    ->whereNumber('id')
                    ->name('destroy');
            });
    });
