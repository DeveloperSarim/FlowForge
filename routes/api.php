<?php

use App\Domain\Assistant\Controllers\McpController;
use App\Domain\Voice\Controllers\VoiceEventController;
use App\Domain\Workflows\Controllers\WebhookController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::any('webhook/{token}', WebhookController::class)->middleware('throttle:120,1');

// Voice providers (Vapi, ElevenLabs) send booking tool calls and call reports here.
Route::post('voice/{token}', VoiceEventController::class)->middleware('throttle:240,1');

// MCP for AI agents (Claude Code, Cursor…): Bearer token from Settings → API & MCP.
Route::match(['get', 'post', 'delete'], 'mcp', McpController::class)->middleware([AuthenticateApiToken::class, 'throttle:240,1']);
