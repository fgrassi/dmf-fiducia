<?php

use App\Http\Controllers\Api\NfseWebhookController;
use App\Http\Controllers\Api\RecalculateChargeController;
use App\Http\Controllers\Api\SicrediWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('nfse/webhook', NfseWebhookController::class);
Route::post('sicredi/webhook', SicrediWebhookController::class);
// a intranet chama por GET (Http::get), como no legado
Route::match(['get', 'post'], 'cron/recalculaCobranca', RecalculateChargeController::class);
