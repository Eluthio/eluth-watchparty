<?php
use Illuminate\Support\Facades\Route;

require_once $pluginPath . '/controller.php';

Route::prefix('/api/plugins/watch-party')->middleware(['api', 'auth.central'])->group(function () {
    Route::get('/proposals',                  [WatchPartyPluginController::class, 'index']);
    Route::post('/proposals',                 [WatchPartyPluginController::class, 'propose'])->middleware('throttle:10,1');
    Route::post('/proposals/{id}/vote',       [WatchPartyPluginController::class, 'vote']);
    Route::post('/proposals/{id}/approve',    [WatchPartyPluginController::class, 'approve']);
    Route::delete('/proposals/{id}/approve',  [WatchPartyPluginController::class, 'reject']);
    Route::delete('/proposals/{id}',          [WatchPartyPluginController::class, 'destroy']);
    Route::delete('/proposals',               [WatchPartyPluginController::class, 'clear']);
    Route::get('/session/{channelId}',        [WatchPartyPluginController::class, 'session']);
    Route::post('/session',                   [WatchPartyPluginController::class, 'syncSession']);
    Route::post('/session/ready',             [WatchPartyPluginController::class, 'sessionReady']);
});
