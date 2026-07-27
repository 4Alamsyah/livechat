<?php

use App\Http\Controllers\Agent\ConversationController;
use App\Http\Controllers\Agent\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->prefix('agent')->name('agent.')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('conversations/{conversation:uuid}/messages', [ConversationController::class, 'messages'])->name('conversations.messages');
    Route::post('conversations/{conversation:uuid}/messages', [ConversationController::class, 'storeMessage'])->name('conversations.messages.store');
    Route::post('conversations/{conversation:uuid}/call', [ConversationController::class, 'signal'])->name('conversations.call');
    Route::post('conversations/{conversation:uuid}/close', [ConversationController::class, 'close'])->name('conversations.close');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
