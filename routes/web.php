<?php

use App\Domain\Assistant\Controllers\AssistantController;
use App\Domain\Demo\DemoController;
use App\Domain\Installer\InstallerController;
use App\Domain\Integrations\Controllers\AppDirectoryController;
use App\Domain\Integrations\Controllers\CredentialController;
use App\Domain\Integrations\Controllers\CustomAppController;
use App\Domain\Integrations\Controllers\IntegrationController;
use App\Domain\Voice\Controllers\VoiceController;
use App\Domain\Workflows\Controllers\ExecutionController;
use App\Domain\Workflows\Controllers\OverviewController;
use App\Domain\Workflows\Controllers\TemplateController;
use App\Domain\Workflows\Controllers\WorkflowController;
use App\Domain\Workflows\Controllers\WorkflowPortController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

// Public files for crawlers and AI agents (dynamic so they carry this install's domain).
Route::get('robots.txt', [SeoController::class, 'robots']);
Route::get('sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('llms.txt', [SeoController::class, 'llms']);
Route::get('llms-full.txt', [SeoController::class, 'llmsFull']);

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

// "/" for guests is the sign-in page itself (200, not a redirect): link previews (WhatsApp, LinkedIn,
// Slack) read its title and image instead of an empty redirect.
Route::get('/', fn () => auth()->check() ? app()->call(OverviewController::class) : app(AuthController::class)->showLogin());

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('forgot-password', [PasswordResetController::class, 'show']);
    Route::post('forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:6,1');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
});

Route::middleware('guest')->group(function () {
    Route::get('invite/{token}', [TeamController::class, 'showInvite']);
    Route::post('invite/{token}', [TeamController::class, 'acceptInvite'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    // Everyone: their own account and look.
    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings/account', [SettingsController::class, 'updateAccount']);
    Route::put('settings/appearance', [SettingsController::class, 'updateAppearance']);
    Route::post('settings/tokens', [SettingsController::class, 'createToken'])->middleware('throttle:20,1');
    Route::delete('settings/tokens/{token}', [SettingsController::class, 'revokeToken']);
    Route::redirect('account', '/settings');
    Route::get('apps', [AppDirectoryController::class, 'index']);
    Route::get('apps/{key}', [AppDirectoryController::class, 'show']);

    // Admins (and the owner): system settings, updates and the team.
    Route::middleware('can:admin')->group(function () {
        Route::put('settings/demo', [DemoController::class, 'save']);
        Route::post('settings/demo/snapshot', [DemoController::class, 'snapshot']);
        Route::put('settings/general', [SettingsController::class, 'updateGeneral']);
        Route::post('settings/scheduler/auto', [SettingsController::class, 'autoCron'])->middleware('throttle:5,1');
        Route::get('settings/updates/check', [UpdateController::class, 'check'])->middleware('throttle:20,1');
        Route::post('settings/updates/download', [UpdateController::class, 'download'])->middleware('throttle:5,1');
        Route::post('settings/updates/upload', [UpdateController::class, 'upload'])->middleware('throttle:300,1');
        Route::post('settings/updates/extract', [UpdateController::class, 'extract']);
        Route::post('settings/updates/finish', [UpdateController::class, 'finish']);
        Route::put('settings/mail', [SettingsController::class, 'updateMail']);
        Route::post('settings/mail/test', [SettingsController::class, 'testMail'])->middleware('throttle:10,1');
        Route::put('settings/notifications', [SettingsController::class, 'updateNotifications']);
        Route::put('settings/ai', [SettingsController::class, 'updateAi']);
        Route::post('settings/ai/test', [SettingsController::class, 'testAi'])->middleware('throttle:10,1');
        Route::post('settings/team', [TeamController::class, 'invite'])->middleware('throttle:30,1');
        Route::put('settings/team/{member}', [TeamController::class, 'update']);
        Route::post('settings/team/{member}/invite', [TeamController::class, 'resendInvite'])->middleware('throttle:30,1');
        Route::delete('settings/team/{member}', [TeamController::class, 'destroy']);
    });

    Route::middleware('can:ai')->group(function () {
        Route::get('assistant', [AssistantController::class, 'index']);
        Route::post('assistant/conversations', [AssistantController::class, 'store']);
        Route::get('assistant/conversations/{conversation}', [AssistantController::class, 'show']);
        Route::post('assistant/conversations/{conversation}/messages', [AssistantController::class, 'message'])->middleware('throttle:30,1');
        Route::post('assistant/conversations/{conversation}/confirm-delete', [AssistantController::class, 'confirmDelete'])->middleware('can:workflows.delete');
        Route::delete('assistant/conversations/{conversation}', [AssistantController::class, 'destroy']);
    });

    Route::middleware('can:workflows.view')->group(function () {
        Route::get('workflows', [WorkflowController::class, 'index']);
        Route::get('workflows/export', [WorkflowPortController::class, 'exportAll']);
        Route::get('workflows/{workflow}', [WorkflowController::class, 'show']);
        Route::get('workflows/{workflow}/export', [WorkflowPortController::class, 'export']);
        Route::get('workflows/{workflow}/runs', [WorkflowController::class, 'runs']);
        Route::get('schedule/preview', [WorkflowController::class, 'schedulePreview']);
        Route::get('templates', [TemplateController::class, 'index']);
        Route::get('executions', [ExecutionController::class, 'index']);
        Route::get('executions/{execution}', [ExecutionController::class, 'show']);
        Route::get('integrations/{key}', IntegrationController::class);
        Route::get('voice', [VoiceController::class, 'index']);
        Route::get('voice/agents/{agent}', [VoiceController::class, 'show']);
        Route::get('voice/calls', [VoiceController::class, 'calls']);
        Route::get('voice/calls/{call}', [VoiceController::class, 'callShow']);
        Route::get('voice/calls/{call}/recording', [VoiceController::class, 'recording']);
        Route::get('voice/bookings', [VoiceController::class, 'bookings']);
        Route::get('voice/options', [VoiceController::class, 'options']);
    });

    // Editing includes using and adding credentials (their secrets are never sent to the browser).
    Route::middleware('can:workflows.edit')->group(function () {
        Route::post('workflows', [WorkflowController::class, 'store']);
        Route::post('workflows/import', [WorkflowPortController::class, 'import'])->middleware('throttle:20,1');
        Route::put('workflows/{workflow}', [WorkflowController::class, 'update']);
        Route::post('workflows/{workflow}/test', [WorkflowController::class, 'test']);
        Route::post('workflows/{workflow}/duplicate', [WorkflowController::class, 'duplicate']);
        Route::post('templates/{key}/use', [TemplateController::class, 'use']);
        Route::post('executions/{execution}/retry', [ExecutionController::class, 'retry']);
        Route::get('credentials', [CredentialController::class, 'index']);
        Route::post('credentials', [CredentialController::class, 'store']);
        Route::put('credentials/{credential}', [CredentialController::class, 'update']);
        Route::get('credentials/{credential}/connect', [CredentialController::class, 'connect']);
        Route::get('oauth/callback', [CredentialController::class, 'callback']);
        Route::post('voice/agents', [VoiceController::class, 'store']);
        Route::post('voice/generate', [VoiceController::class, 'generate'])->middleware('throttle:20,1');
        Route::put('voice/agents/{agent}', [VoiceController::class, 'update']);
        Route::get('voice/agents/{agent}/numbers', [VoiceController::class, 'numbers'])->middleware('throttle:30,1');
        Route::get('voice/agents/{agent}/voices', [VoiceController::class, 'voices'])->middleware('throttle:60,1');
        Route::post('voice/agents/{agent}/voices/add', [VoiceController::class, 'addVoice'])->middleware('throttle:20,1');
        Route::post('voice/agents/{agent}/speak', [VoiceController::class, 'speak'])->middleware('throttle:60,1');
        Route::post('voice/agents/{agent}/webhooks/test', [VoiceController::class, 'webhookTest'])->middleware('throttle:20,1');
        Route::post('voice/agents/{agent}/call', [VoiceController::class, 'call'])->middleware('throttle:20,1');
        Route::post('voice/agents/{agent}/talk', [VoiceController::class, 'talk'])->middleware('throttle:60,1');
        Route::post('voice/agents/{agent}/talk/{call}/end', [VoiceController::class, 'endTalk']);
        Route::put('voice/bookings/{booking}', [VoiceController::class, 'updateBooking']);
        Route::post('apps/custom', [CustomAppController::class, 'store'])->middleware('throttle:20,1');
    });

    Route::middleware('can:workflows.delete')->group(function () {
        Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy']);
        Route::delete('credentials/{credential}', [CredentialController::class, 'destroy']);
        Route::delete('voice/agents/{agent}', [VoiceController::class, 'destroy']);
        Route::delete('apps/custom/{key}', [CustomAppController::class, 'destroy']);
    });
});
