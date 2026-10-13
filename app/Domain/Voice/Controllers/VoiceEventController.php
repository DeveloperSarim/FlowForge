<?php

namespace App\Domain\Voice\Controllers;

use App\Domain\Voice\Models\VoiceAgent;
use App\Domain\Voice\VoiceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Public endpoint the voice provider calls during and after a call: /api/voice/{token}.
 * The token in the URL is the agent's secret; it is only ever sent to the agent's own provider.
 */
class VoiceEventController extends Controller
{
    public function __invoke(Request $request, string $token, VoiceService $voice): JsonResponse
    {
        $agent = VoiceAgent::query()->where('token', $token)->first();
        if (! $agent) {
            return response()->json(['message' => 'Unknown agent.'], 404);
        }
        try {
            return response()->json($voice->handleEvent($agent, $request->json()->all() ?: $request->all()));
        } catch (Throwable $e) {
            report($e);

            // Vapi ignores non-200 answers, so a failure is reported as the tool result the agent can relay.
            return response()->json(['result' => 'Sorry, the booking system had a problem. Please take the caller\'s details and say the team will call back.']);
        }
    }
}
