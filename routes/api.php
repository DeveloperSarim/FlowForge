<?php

use App\Domain\Workflows\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::any('webhook/{token}', WebhookController::class)->middleware('throttle:120,1');
