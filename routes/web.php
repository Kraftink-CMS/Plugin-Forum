<?php

use App\Http\Middleware\NoIndex;
use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Forum\Http\Controllers\AttachmentController;
use KraftInk\Plugins\Forum\Http\Controllers\ForumController;
use KraftInk\Plugins\Forum\Http\Controllers\DraftController;
use KraftInk\Plugins\Forum\Http\Controllers\PollController;
use KraftInk\Plugins\Forum\Http\Controllers\PostHistoryController;
use KraftInk\Plugins\Forum\Http\Controllers\ProfileController;

Route::prefix('forum')
    ->name('plugins.forum.public.')
    ->middleware(['web', 'permission:forum.view'])
    ->group(function () {
        Route::name('home.')->group(function () {
            Route::get('/', [ForumController::class, 'index'])
                ->middleware('capability:extension,forum')
                ->name('index');
        });

        Route::prefix('search')
            ->name('search.')
            ->group(function () {
                Route::get('/', [ForumController::class, 'search'])
                    ->middleware(['throttle:30,1', NoIndex::class])
                    ->name('index');
            });

        Route::name('profiles.')->group(function () {
            Route::get('/members/{id}', [ProfileController::class, 'show'])
                ->whereNumber('id')
                ->name('show');
            Route::middleware('auth')->group(function () {
                Route::get('/profile', [ProfileController::class, 'edit'])->name('edit');
                Route::put('/profile', [ProfileController::class, 'store'])
                    ->middleware('throttle:10,1')
                    ->name('update');
            });
        });

        Route::prefix('categories')
            ->name('categories.')
            ->group(function () {
                Route::get('/{id}', [ForumController::class, 'category'])
                    ->whereNumber('id')
                    ->name('show');
            });

        Route::name('topics.')->group(function () {
            Route::get('/topics/{id}/{slug?}', [ForumController::class, 'topic'])
                ->whereNumber('id')
                ->where('slug', '[a-z0-9-]+')
                ->name('show');
            Route::middleware('auth')->group(function () {
                Route::get('/following', [ForumController::class, 'following'])->name('following');
                Route::post('/categories/{id}/topics', [ForumController::class, 'store'])
                    ->whereNumber('id')
                    ->middleware('throttle:forum.publish')
                    ->name('store');
                Route::post('/topics/{id}/reply', [ForumController::class, 'reply'])
                    ->whereNumber('id')
                    ->middleware('throttle:forum.publish')
                    ->name('reply');
                Route::post('/topics/{id}/follow', [ForumController::class, 'follow'])
                    ->whereNumber('id')
                    ->name('follow');
                Route::patch('/topics/{id}/title', [ForumController::class, 'renameTopic'])
                    ->whereNumber('id')
                    ->middleware('throttle:20,1')
                    ->name('title.update');
                Route::patch('/topics/{id}/moderation', [ForumController::class, 'moderateTopic'])
                    ->whereNumber('id')
                    ->name('moderation.update');
                Route::put('/topics/{id}/tags', [ForumController::class, 'tags'])
                    ->whereNumber('id')
                    ->name('tags.update');
            });
        });

        Route::prefix('attachments')
            ->name('attachments.')
            ->group(function () {
                Route::get('/{id}/download', [AttachmentController::class, 'download'])
                    ->whereNumber('id')
                    ->name('download');
                Route::get('/{id}/image', [AttachmentController::class, 'image'])
                    ->whereNumber('id')
                    ->name('image');
                Route::middleware('auth')->group(function () {
                    Route::delete('/{id}', [AttachmentController::class, 'destroy'])
                        ->whereNumber('id')
                        ->name('destroy');
                });
            });

        Route::prefix('drafts')
            ->name('drafts.')
            ->group(function () {
                Route::middleware('auth')->group(function () {
                    Route::post('/', [DraftController::class, 'store'])
                        ->middleware('throttle:30,1')
                        ->name('store');
                    Route::delete('/', [DraftController::class, 'discard'])
                        ->middleware('throttle:30,1')
                        ->name('destroy');
                });
            });

        Route::prefix('categories')
            ->name('images.')
            ->group(function () {
                Route::middleware('auth')->group(function () {
                    Route::post('/{id}/images', [AttachmentController::class, 'upload'])
                        ->whereNumber('id')
                        ->middleware('throttle:20,1')
                        ->name('store');
                });
            });

        Route::prefix('posts')
            ->name('posts.')
            ->group(function () {
                Route::middleware('auth')->group(function () {
                    Route::put('/{id}', [PostHistoryController::class, 'edit'])
                        ->whereNumber('id')
                        ->middleware('throttle:20,1')
                        ->name('update');
                    Route::delete('/{id}', [PostHistoryController::class, 'destroy'])
                        ->whereNumber('id')
                        ->name('destroy');
                    Route::patch('/{id}/visibility', [ForumController::class, 'moderatePost'])
                        ->whereNumber('id')
                        ->name('visibility.update');
                    Route::get('/{id}/history', [PostHistoryController::class, 'index'])
                        ->whereNumber('id')
                        ->name('history');
                    Route::post('/{id}/history/{revision}', [PostHistoryController::class, 'restore'])
                        ->whereNumber(['id', 'revision'])
                        ->middleware('throttle:10,1')
                        ->name('revisions.restore');
                    Route::post('/{id}/report', [ForumController::class, 'report'])
                        ->whereNumber('id')
                        ->middleware('throttle:5,10')
                        ->name('report');
                    Route::post('/{id}/like', [ForumController::class, 'like'])
                        ->whereNumber('id')
                        ->middleware('throttle:30,1')
                        ->name('like');
                });
            });

        Route::prefix('topics')
            ->name('polls.')
            ->group(function () {
                Route::middleware('auth')->group(function () {
                    Route::post('/{id}/poll', [PollController::class, 'save'])
                        ->whereNumber('id')
                        ->middleware('throttle:10,1')
                        ->name('store');
                    Route::put('/{id}/poll', [PollController::class, 'save'])
                        ->whereNumber('id')
                        ->middleware('throttle:10,1')
                        ->name('update');
                    Route::post('/{id}/poll/vote', [PollController::class, 'vote'])
                        ->whereNumber('id')
                        ->middleware('throttle:20,1')
                        ->name('vote');
                    Route::delete('/{id}/poll/vote', [PollController::class, 'withdraw'])
                        ->whereNumber('id')
                        ->middleware('throttle:20,1')
                        ->name('withdraw');
                    Route::patch('/{id}/poll', [PollController::class, 'close'])
                        ->whereNumber('id')
                        ->name('close');
                    Route::delete('/{id}/poll', [PollController::class, 'destroy'])
                        ->whereNumber('id')
                        ->name('destroy');
                });
            });
    });
