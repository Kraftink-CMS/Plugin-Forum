<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Forum\Http\Controllers\Api\PublicDataController;

Route::prefix('api/forum')
    ->name('plugins.forum.api.')
    ->middleware(['web', 'permission:forum.view', 'throttle:60,1'])
    ->group(function () {
        Route::prefix('categories')
            ->name('categories.')
            ->group(function () {
                Route::get('/', [PublicDataController::class, 'categories'])->name('index');
                Route::get('/{id}', [PublicDataController::class, 'showCategory'])
                    ->whereNumber('id')
                    ->name('show');
                Route::get('/{id}/topics', [PublicDataController::class, 'topics'])
                    ->whereNumber('id')
                    ->name('topics.index');
                Route::get('/{id}/feeds/{format}', [PublicDataController::class, 'categoryFeed'])
                    ->whereNumber('id')
                    ->whereIn('format', ['rss', 'atom'])
                    ->name('feeds.index');
            });

        Route::prefix('topics')
            ->name('topics.')
            ->group(function () {
                Route::get('/', [PublicDataController::class, 'topics'])->name('index');
                Route::get('/{id}', [PublicDataController::class, 'topic'])
                    ->whereNumber('id')
                    ->name('show');
                Route::get('/{id}/posts', [PublicDataController::class, 'posts'])
                    ->whereNumber('id')
                    ->name('posts.index');
            });

        Route::prefix('feeds')
            ->name('feeds.')
            ->group(function () {
                Route::get('/{format}', [PublicDataController::class, 'feed'])
                    ->whereIn('format', ['rss', 'atom'])
                    ->name('index');
            });
    });
