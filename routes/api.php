<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::get('/meta-to-app/webhook',[WebhookController::class,'verifyMetaWebhookCall']);
Route::post('/meta-to-app/webhook',[WebhookController::class,'receiveMetaWebhookCall']);
