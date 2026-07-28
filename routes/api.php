<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\WidgetSettingsController;
use App\Http\Controllers\Api\WidgetSiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('widget')->group(function () {
    Route::get('config', ConfigController::class);
    Route::get('announcements', AnnouncementController::class);
    Route::get('settings', WidgetSettingsController::class);
    Route::post('sites/heartbeat', WidgetSiteController::class);
    Route::post('conversations', [ConversationController::class, 'store']);
    Route::get('conversations/{conversation:uuid}/messages', [MessageController::class, 'index']);
    Route::post('conversations/{conversation:uuid}/messages', [MessageController::class, 'store']);
    Route::post('conversations/{conversation:uuid}/call', [MessageController::class, 'signal']);
    Route::post('conversations/{conversation:uuid}/close', [ConversationController::class, 'close']);
});
