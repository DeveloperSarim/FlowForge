<?php

use App\Domain\Assistant\Controllers\AssistantController;
use App\Domain\Installer\InstallerController;
use App\Domain\Integrations\Controllers\AppDirectoryController;
use App\Domain\Integrations\Controllers\CredentialController;
use App\Domain\Integrations\Controllers\IntegrationController;
use App\Domain\Workflows\Controllers\ExecutionController;
use App\Domain\Workflows\Controllers\OverviewController;
use App\Domain\Workflows\Controllers\TemplateController;
use App\Domain\Workflows\Controllers\WorkflowController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('install')->controller(InstallerController::class)->group(function () {
    Route::get('/', 'show');
    Route::post('database/test', 'testDatabase')->middleware('throttle:20,1');
    Route::post('database', 'database');
    Route::post('admin', 'admin');
    Route::post('mail', 'mail');
    Route::post('mail/test', 'testMail')->middleware('throttle:10,1');
    Route::post('mail/skip', 'skipMail');
    Route::get('heartbeat', 'heartbeat');
    Route::post('cron/auto', 'autoCron')->middleware('throttle:5,1');
    Route::post('finish', 'finish');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('forgot-password', [PasswordResetController::class, 'show']);
    Route::post('forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:6,1');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::get('/', OverviewController::class);
    Route::post('logout', [AuthController::class, 'logout']);

    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings/account', [SettingsController::class, 'updateAccount']);
    Route::put('settings/appearance', [SettingsController::class, 'updateAppearance']);
    Route::put('settings/general', [SettingsController::class, 'updateGeneral']);
    Route::post('settings/scheduler/auto', [SettingsController::class, 'autoCron'])->middleware('throttle:5,1');
    Route::put('settings/mail', [SettingsController::class, 'updateMail']);
    Route::post('settings/mail/test', [SettingsController::class, 'testMail'])->middleware('throttle:10,1');
    Route::put('settings/notifications', [SettingsController::class, 'updateNotifications']);
    Route::put('settings/ai', [SettingsController::class, 'updateAi']);
    Route::post('settings/ai/test', [SettingsController::class, 'testAi'])->middleware('throttle:10,1');

    Route::get('assistant', [AssistantController::class, 'index']);
    Route::post('assistant/conversations', [AssistantController::class, 'store']);
    Route::get('assistant/conversations/{conversation}', [AssistantController::class, 'show']);
    Route::post('assistant/conversations/{conversation}/messages', [AssistantController::class, 'message'])->middleware('throttle:30,1');
    Route::post('assistant/conversations/{conversation}/confirm-delete', [AssistantController::class, 'confirmDelete']);
    Route::delete('assistant/conversations/{conversation}', [AssistantController::class, 'destroy']);
    Route::redirect('account', '/settings');

    Route::get('schedule/preview', [WorkflowController::class, 'schedulePreview']);
    Route::resource('workflows', WorkflowController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('workflows/{workflow}/test', [WorkflowController::class, 'test']);
    Route::post('workflows/{workflow}/duplicate', [WorkflowController::class, 'duplicate']);
    Route::get('workflows/{workflow}/runs', [WorkflowController::class, 'runs']);

    Route::get('templates', [TemplateController::class, 'index']);
    Route::post('templates/{key}/use', [TemplateController::class, 'use']);

    Route::get('executions', [ExecutionController::class, 'index']);
    Route::get('executions/{execution}', [ExecutionController::class, 'show']);
    Route::post('executions/{execution}/retry', [ExecutionController::class, 'retry']);

    Route::get('apps', [AppDirectoryController::class, 'index']);
    Route::get('apps/{key}', [AppDirectoryController::class, 'show']);
    Route::get('integrations/{key}', IntegrationController::class);

    Route::resource('credentials', CredentialController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('credentials/{credential}/connect', [CredentialController::class, 'connect']);
    Route::get('oauth/callback', [CredentialController::class, 'callback']);
});
